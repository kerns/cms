<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\controllers;

use Craft;
use craft\app\enums\PluginUpdateStatus;
use craft\app\errors\EtException;
use craft\app\helpers\App;
use craft\app\helpers\ArrayHelper;
use craft\app\helpers\Update;
use craft\app\helpers\Url;
use craft\app\web\Controller;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * The UpdateController class is a controller that handles various update related tasks such as checking for available
 * updates and running manual and auto-updates.
 *
 * Note that all actions in the controller, except for [[actionPrepare]], [[actionBackupDatabase]],
 * [[actionUpdateDatabase]], [[actionCleanUp]] and [[actionRollback]] require an authenticated Craft session
 * via [[Controller::allowAnonymous]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class UpdateController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = [
        'prepare',
        'backup-database',
        'update-database',
        'clean-up',
        'rollback'
    ];

    // Public Methods
    // =========================================================================

    // Auto Updates
    // -------------------------------------------------------------------------

    /**
     * Returns the available updates.
     *
     * @return Response
     */
    public function actionGetAvailableUpdates()
    {
        $this->requirePermission('performUpdates');

        try {
            $updates = Craft::$app->getUpdates()->getUpdates(true);
        } catch (EtException $e) {
            $updates = false;

            if ($e->getCode() == 10001) {
                return $this->asErrorJson($e->getMessage());
            }
        }

        if ($updates) {
            $response = ArrayHelper::toArray($updates);
            $response['allowAutoUpdates'] = Craft::$app->getConfig()->allowAutoUpdates();

            return $this->asJson($response);
        } else {
            return $this->asErrorJson(Craft::t('app', 'Could not fetch available updates at this time.'));
        }
    }

    /**
     * Returns the update info JSON.
     *
     * @return Response
     */
    public function actionGetUpdates()
    {
        $this->requirePermission('performUpdates');

        $this->requireAjaxRequest();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        $return = [];
        $updateInfo = Craft::$app->getUpdates()->getUpdates();

        if (!$updateInfo) {
            return $this->asErrorJson(Craft::t('app', 'There was a problem getting the latest update information.'));
        }

        try {
            switch ($handle) {
                case 'all': {
                    // Craft first.
                    $return[] = [
                        'handle' => 'Craft',
                        'name' => 'Craft',
                        'version' => $updateInfo->app->latestVersion.'.'.$updateInfo->app->latestBuild,
                        'critical' => $updateInfo->app->criticalUpdateAvailable,
                        'releaseDate' => $updateInfo->app->latestDate->getTimestamp()
                    ];

                    // Plugins
                    if ($updateInfo->plugins !== null) {
                        foreach ($updateInfo->plugins as $plugin) {
                            if ($plugin->status == PluginUpdateStatus::UpdateAvailable && count($plugin->releases) > 0) {
                                $return[] = [
                                    'handle' => $plugin->class,
                                    'name' => $plugin->displayName,
                                    'version' => $plugin->latestVersion,
                                    'critical' => $plugin->criticalUpdateAvailable,
                                    'releaseDate' => $plugin->latestDate->getTimestamp()
                                ];
                            }
                        }
                    }

                    break;
                }

                case 'craft': {
                    $return[] = [
                        'handle' => 'Craft',
                        'name' => 'Craft',
                        'version' => $updateInfo->app->latestVersion.'.'.$updateInfo->app->latestBuild,
                        'critical' => $updateInfo->app->criticalUpdateAvailable,
                        'releaseDate' => $updateInfo->app->latestDate->getTimestamp()
                    ];
                    break;
                }

                // We assume it's a plugin handle.
                default: {
                    if (!empty($updateInfo->plugins)) {
                        if (isset($updateInfo->plugins[$handle]) && $updateInfo->plugins[$handle]->status == PluginUpdateStatus::UpdateAvailable && count($updateInfo->plugins[$handle]->releases) > 0) {
                            $return[] = [
                                'handle' => $updateInfo->plugins[$handle]->handle,
                                'name' => $updateInfo->plugins[$handle]->displayName,
                                'version' => $updateInfo->plugins[$handle]->latestVersion,
                                'critical' => $updateInfo->plugins[$handle]->criticalUpdateAvailable,
                                'releaseDate' => $updateInfo->plugins[$handle]->latestDate->getTimestamp()
                            ];
                        } else {
                            return $this->asErrorJson(Craft::t('app', 'Could not find any update information for the plugin with handle “{handle}”.', ['handle' => $handle]));
                        }
                    } else {
                        return $this->asErrorJson(Craft::t('app', 'Could not find any update information for the plugin with handle “{handle}”.', ['handle' => $handle]));
                    }
                }
            }

            return $this->asJson(['success' => true, 'updateInfo' => $return]);
        } catch (\Exception $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }

    /**
     * Called during both a manual and auto-update.
     *
     * @return Response
     */
    public function actionPrepare()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');
        $handle = $this->_getFixedHandle($data);

        $manual = false;
        if (!$this->_isManualUpdate($data)) {
            // If it's not a manual update, make sure they have auto-update permissions.
            $this->requirePermission('performUpdates');

            if (!Craft::$app->getConfig()->allowAutoUpdates()) {
                return $this->asJson([
                    'alive' => true,
                    'errorDetails' => Craft::t('app', 'Auto-updating is disabled on this system.'),
                    'finished' => true
                ]);
            }
        } else {
            $manual = true;
        }

        $return = Craft::$app->getUpdates()->prepareUpdate($manual, $handle);

        if (!$return['success']) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => $return['message'],
                'finished' => true
            ]);
        }

        if ($manual) {
            return $this->asJson([
                'alive' => true,
                'nextStatus' => Craft::t('app', 'Backing-up database…'),
                'nextAction' => 'update/backup-database',
                'data' => $data
            ]);
        } else {
            $data['md5'] = $return['md5'];

            return $this->asJson([
                'alive' => true,
                'nextStatus' => Craft::t('app', 'Downloading update…'),
                'nextAction' => 'update/process-download',
                'data' => $data
            ]);
        }
    }

    /**
     * Called during an auto-update.
     *
     * @return Response
     */
    public function actionProcessDownload()
    {
        // This method should never be called in a manual update.
        $this->requirePermission('performUpdates');

        $this->requirePostRequest();
        $this->requireAjaxRequest();

        if (!Craft::$app->getConfig()->allowAutoUpdates()) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => Craft::t('app', 'Auto-updating is disabled on this system.'),
                'finished' => true
            ]);
        }

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');

        $handle = $this->_getFixedHandle($data);
        $return = Craft::$app->getUpdates()->processUpdateDownload($data['md5'], $handle);
        $return['handle'] = $handle;

        if (!$return['success']) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => $return['message'],
                'finished' => true
            ]);
        }

        unset($return['success']);

        return $this->asJson([
            'alive' => true,
            'nextStatus' => Craft::t('app', 'Backing-up files…'),
            'nextAction' => 'update/backup-files',
            'data' => $return
        ]);
    }

    /**
     * Called during an auto-update.
     *
     * @return Response
     */
    public function actionBackupFiles()
    {
        // This method should never be called in a manual update.
        $this->requirePermission('performUpdates');

        $this->requirePostRequest();
        $this->requireAjaxRequest();

        if (!Craft::$app->getConfig()->allowAutoUpdates()) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => Craft::t('app', 'Auto-updating is disabled on this system.'),
                'finished' => true
            ]);
        }

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');
        $handle = $this->_getFixedHandle($data);

        $return = Craft::$app->getUpdates()->backupFiles($data['uid'], $handle);
        $return['handle'] = $handle;

        if (!$return['success']) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => $return['message'],
                'finished' => true
            ]);
        }

        return $this->asJson([
            'alive' => true,
            'nextStatus' => Craft::t('app', 'Updating files…'),
            'nextAction' => 'update/update-files',
            'data' => $data
        ]);
    }

    /**
     * Called during an auto-update.
     *
     * @return Response
     */
    public function actionUpdateFiles()
    {
        // This method should never be called in a manual update.
        $this->requirePermission('performUpdates');

        $this->requirePostRequest();
        $this->requireAjaxRequest();

        if (!Craft::$app->getConfig()->allowAutoUpdates()) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => Craft::t('app', 'Auto-updating is disabled on this system.'),
                'finished' => true
            ]);
        }

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');
        $handle = $this->_getFixedHandle($data);

        $return = Craft::$app->getUpdates()->updateFiles($data['uid'], $handle);
        $return['handle'] = $handle;

        if (!$return['success']) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => $return['message'],
                'nextStatus' => Craft::t('app', 'An error was encountered. Rolling back…'),
                'nextAction' => 'update/rollback'
            ]);
        }

        return $this->asJson([
            'alive' => true,
            'nextStatus' => Craft::t('app', 'Backing-up database…'),
            'nextAction' => 'update/backup-database',
            'data' => $data
        ]);
    }

    /**
     * Called during both a manual and auto-update.
     *
     * @return Response
     */
    public function actionBackupDatabase()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');

        $handle = $this->_getFixedHandle($data);

        if (Craft::$app->getConfig()->get('backupDbOnUpdate')) {
            if ($handle !== 'craft') {
                $plugin = Craft::$app->getPlugins()->getPlugin($handle);
            }

            // If this a plugin, make sure it actually has new migrations before backing up the database.
            if ($handle === 'craft' || (!empty($plugin) && $plugin->getMigrator()->getNewMigrations())) {
                $return = Craft::$app->getUpdates()->backupDatabase();

                if (!$return['success']) {
                    return $this->asJson([
                        'alive' => true,
                        'errorDetails' => $return['message'],
                        'nextStatus' => Craft::t('app', 'An error was encountered. Rolling back…'),
                        'nextAction' => 'update/rollback'
                    ]);
                }

                if (isset($return['dbBackupPath'])) {
                    $data['dbBackupPath'] = $return['dbBackupPath'];
                }
            }
        }

        return $this->asJson([
            'alive' => true,
            'nextStatus' => Craft::t('app', 'Updating database…'),
            'nextAction' => 'update/update-database',
            'data' => $data
        ]);
    }

    /**
     * Called during both a manual and auto-update.
     *
     * @return Response
     */
    public function actionUpdateDatabase()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');

        $handle = $this->_getFixedHandle($data);

        if (isset($data['dbBackupPath'])) {
            $return = Craft::$app->getUpdates()->updateDatabase($handle);
        } else {
            $return = Craft::$app->getUpdates()->updateDatabase($handle);
        }

        $return['handle'] = $handle;

        if (!$return['success']) {
            return $this->asJson([
                'alive' => true,
                'errorDetails' => $return['message'],
                'nextStatus' => Craft::t('app', 'An error was encountered. Rolling back…'),
                'nextAction' => 'update/rollback'
            ]);
        }

        return $this->asJson([
            'alive' => true,
            'nextStatus' => Craft::t('app', 'Cleaning up…'),
            'nextAction' => 'update/clean-up',
            'data' => $data
        ]);
    }

    /**
     * Performs maintenance and clean up tasks after an update.
     *
     * Called during both a manual and auto-update.
     *
     * @return Response
     */
    public function actionCleanUp()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');

        if ($this->_isManualUpdate($data)) {
            $uid = false;
        } else {
            $uid = $data['uid'];
        }

        $handle = $this->_getFixedHandle($data);

        $oldVersion = false;

        // Grab the old version from the manifest data before we nuke it.
        $manifestData = Update::getManifestData(Update::getUnzipFolderFromUID($uid), $handle);

        if ($manifestData && $handle == 'craft') {
            $oldVersion = Update::getLocalVersionFromManifest($manifestData);
        }

        Craft::$app->getUpdates()->updateCleanUp($uid, $handle);

        // New major Craft CMS version?
        if ($handle == 'craft' && $oldVersion && App::getMajorVersion($oldVersion) < App::getMajorVersion(Craft::$app->version)) {
            $returnUrl = Url::getUrl('whats-new');
        } else {
            $returnUrl = Craft::$app->getConfig()->get('postCpLoginRedirect');
        }

        return $this->asJson([
            'alive' => true,
            'finished' => true,
            'returnUrl' => $returnUrl
        ]);
    }

    /**
     * Can be called during both a manual and auto-update.
     *
     * @return Response
     * @throws ServerErrorHttpException if reasons
     */
    public function actionRollback()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $data = Craft::$app->getRequest()->getRequiredBodyParam('data');
        $handle = $this->_getFixedHandle($data);

        if ($this->_isManualUpdate($data)) {
            $uid = false;
        } else {
            $uid = $data['uid'];
        }

        if (isset($data['dbBackupPath'])) {
            $return = Craft::$app->getUpdates()->rollbackUpdate($uid, $handle, $data['dbBackupPath']);
        } else {
            $return = Craft::$app->getUpdates()->rollbackUpdate($uid, $handle);
        }

        if (!$return['success']) {
            // Let the JS handle the exception response.
            throw new ServerErrorHttpException($return['message']);
        }

        return $this->asJson([
            'alive' => true,
            'finished' => true,
            'rollBack' => true
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param $data
     *
     * @return boolean
     */
    private function _isManualUpdate($data)
    {
        if (isset($data['manualUpdate']) && $data['manualUpdate'] == 1) {
            return true;
        }

        return false;
    }

    /**
     * @param $data
     *
     * @return string
     */
    private function _getFixedHandle($data)
    {
        if (!isset($data['handle'])) {
            return 'craft';
        } else {
            return $data['handle'];
        }
    }
}
