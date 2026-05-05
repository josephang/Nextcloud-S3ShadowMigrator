<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISection;

class AdminSection implements ISection {
    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 's3shadowmigrator';
    }

    public function getName(): string {
        return $this->l->t('S3 Shadow Migrator');
    }

    public function getPriority(): int {
        return 99;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('core', 'places/link.svg');
    }
}
