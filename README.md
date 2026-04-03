# Semitexa Storage

Driver-based file storage abstraction with local and S3/MinIO backends.

## Purpose

Provides a unified file storage API across local filesystem and S3-compatible object stores. Handles path namespacing for tenant isolation, metadata tracking via ORM, and CDN-ready URL generation.

## Role in Semitexa

Depends on `semitexa/core` and `semitexa/orm`. Used by `semitexa/mail` for attachments and `semitexa/platform-user` for avatar storage. Drivers are resolved via the container and selected per storage context.

## Key Features

- `StorageManager` facade with driver selection
- `LocalDriver` for filesystem storage
- `S3Driver` for S3/MinIO object storage
- `StorageDriverInterface` for custom backends
- `StoredObjectDescriptor` and `StoredObjectMetadata` value objects
- Tenant-aware path namespacing
- `StorageObjectStoreInterface` for metadata persistence

## Notes

Storage paths are automatically namespaced per tenant when tenancy is active. Drivers are stateless and safe for concurrent use in Swoole.
