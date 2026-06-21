# Changelog

## 2026-06 — Public repository baseline cleanup

This update prepares the public repository for a cleaner and safer open-source baseline.

### Changed

* Removed internal operational files from the public repository.
* Removed local environment and private configuration artifacts from tracked files.
* Reduced the public repository surface to application code and required project files.
* Cleaned obsolete remote branches before historical repository cleanup.
* Preserved required dependency lock files for reproducible Laravel and frontend builds.

### Security

* Previously exposed credentials have been revoked outside the repository.
* The current public HEAD has been cleaned.
* Historical Git cleanup is planned as a separate controlled operation.

### Notes

This changelog intentionally summarizes the public repository state only.

It does not document internal task files, private agent workflows, local backup paths, operational reports, or deployment procedures.
