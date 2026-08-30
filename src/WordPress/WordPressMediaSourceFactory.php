<?php

namespace SecureS3StorageForWordpress\WordPress;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;

final class WordPressMediaSourceFactory
{
    public function create(): MediaSource
    {
        // A network main site's upload root may also contain other sites' files.
        // Network-wide ownership and restore scope require a separate design.
        if (is_multisite()) {
            throw new RuntimeException('Media inventory currently supports single-site installations only.');
        }
        // No request-supplied path, no mkdir, and no assumption about wp-content.
        $uploads = wp_get_upload_dir();
        if (! empty($uploads['error']) || empty($uploads['basedir']) || ! is_string($uploads['basedir'])) {
            throw new RuntimeException('Unable to resolve the WordPress upload directory.');
        }
        return new MediaSource($uploads['basedir']);
    }
}
