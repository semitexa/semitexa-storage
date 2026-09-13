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

Storage paths are automatically namespaced per tenant when tenancy is active. Drivers hold no mutable state, so one instance is safe to share between coroutines. They do not serialise writes ACROSS processes: two workers writing the same key race for it and the last write wins, as with any filesystem or object store. One case is worth naming, because it is specific to this driver: two keys whose metadata paths structurally collide (`report` and `report.json/child`) are refused before anything is written, but written at the same moment from two processes, one of them can persist its object and still report the collision. See `LocalDriver::assertMetadataWritable()`.

`LocalDriver` keeps each object's MIME type in a reserved `.meta/` subtree of the storage root, mirroring the object's own path. That subtree is not addressable through the object API: a key resolving into it is refused with a `StorageException`, so driver bookkeeping can never overwrite a caller's object. Any other key is allowed, including one ending in `.meta.json`.

Objects written before this layout kept their metadata beside them as `<key>.meta.json`. Those are still read, but only when they carry exactly the one key the driver used to write; they are never deleted, because after the move they are ordinary objects in the caller's namespace. Clearing the leftovers is an operator's decision.
