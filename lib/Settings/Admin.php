<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Settings;

use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IGroupManager;

class Admin implements ISettings {
    private IConfig $config;
    private IDBConnection $db;
    private IUserManager $userManager;
    private IGroupManager $groupManager;

    public function __construct(
        IConfig $config,
        IDBConnection $db,
        IUserManager $userManager,
        IGroupManager $groupManager
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
    }

    public function getForm() {
        // Fetch S3 Mounts
        $query = $this->db->getQueryBuilder();
        $query->select('mount_id', 'mount_point')
              ->from('external_mounts')
              ->where($query->expr()->eq('storage_backend', $query->createNamedParameter('amazons3')));
        $mounts = $query->executeQuery()->fetchAll();

        // Fetch Users and Groups
        $users = [];
        foreach ($this->userManager->search('') as $user) {
            $users[] = $user->getUID();
        }
        $groups = [];
        foreach ($this->groupManager->search('') as $group) {
            $groups[] = $group->getGID();
        }

        $parameters = [
            'auto_upload_enabled' => $this->config->getAppValue('s3shadowmigrator', 'auto_upload_enabled', 'no'),
            's3_mount_id'         => $this->config->getAppValue('s3shadowmigrator', 's3_mount_id', '0'),
            'throttle_mode'       => $this->config->getAppValue('s3shadowmigrator', 'throttle_mode', 'unlimited'),
            'custom_throttle_mb'  => $this->config->getAppValue('s3shadowmigrator', 'custom_throttle_mb', '50'),
            'exclusion_mode'      => $this->config->getAppValue('s3shadowmigrator', 'exclusion_mode', 'blacklist'),
            'excluded_users'      => $this->config->getAppValue('s3shadowmigrator', 'excluded_users', ''),
            'mirror_paths'        => $this->config->getAppValue('s3shadowmigrator', 'mirror_paths', 'Notes/'),
            'available_mounts'    => $mounts,
            'available_users'     => $users,
            'available_groups'    => $groups,
        ];

        // Load admin settings JS as an external file — inline <script> tags in
        // settings templates are silently dropped by browsers when Nextcloud
        // injects the section HTML via innerHTML.
        \OCP\Util::addScript('s3shadowmigrator', 'settings-admin');

        return new TemplateResponse('s3shadowmigrator', 'settings-admin', $parameters);
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 10;
    }
}
