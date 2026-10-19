This release records version 0.3.0 already published on WordPress.org on 2026-10-17. It does not replace or change that WordPress.org package.

0.3.0 adds the internal Recovery Set model, resumable DB/media collection, verified S3 manifest publication, and isolation from standalone backup state. Caller-owned S3 upload streams remain open on success and failure. Existing standalone backup controls remain unchanged.

Recovery Set operator status/admin interfaces, Cron orchestration, cleanup commands and schedule-assessment settings belong to the separate upcoming 0.4.0 work, and are not included here. This release does not provide a general one-click recovery workflow.

Source: 627e47c2b31527ef1486e582b79fd5dba875843f. Its runtime/build inputs match the documented production runtime commit d9406fac340ef1e4400af67364f165fbfd5f1587.

The attached installation ZIP is the exact WordPress.org 0.3.0 download, not a rebuild. SHA-256: `ea7c6a3cd7f76f949a95af296af0c799556f38aeb2c249aed950f9ef2d6d8080`.

Use the attached plugin ZIP for installation. GitHub's automatically generated source archives do not include the packaged dependencies. Historical test scope and limits are recorded in the tagged repository documentation; later 0.4.0 candidate tests do not apply retroactively to this release.
