<?php
declare(strict_types=1);

namespace App\Models;

class CollectionFeatureModel
{
    public static function byId(string $id): ?array
    {
        return DB::one('SELECT * FROM collection_feature_authorizations WHERE id=? LIMIT 1', [$id]);
    }

    public static function byUserAndActivity(string $userId, string $activityUri): ?array
    {
        return DB::one(
            'SELECT * FROM collection_feature_authorizations WHERE user_id=? AND activity_uri=? LIMIT 1',
            [$userId, $activityUri]
        );
    }

    public static function byUserAndCollection(string $userId, string $collectionUri): ?array
    {
        return DB::one(
            'SELECT * FROM collection_feature_authorizations WHERE user_id=? AND remote_collection_uri=? LIMIT 1',
            [$userId, $collectionUri]
        );
    }

    public static function acceptedByIdForUser(string $id, string $userId): ?array
    {
        return DB::one(
            "SELECT * FROM collection_feature_authorizations
             WHERE id=? AND user_id=? AND state='accepted'
             LIMIT 1",
            [$id, $userId]
        );
    }

    public static function upsertDecision(
        string $userId,
        string $remoteActorId,
        string $remoteCollectionUri,
        string $activityUri,
        string $state
    ): array {
        $existing = self::byUserAndActivity($userId, $activityUri)
            ?? self::byUserAndCollection($userId, $remoteCollectionUri);
        $now = now_iso();

        if ($existing) {
            DB::update('collection_feature_authorizations', [
                'remote_actor_id' => $remoteActorId,
                'remote_collection_uri' => $remoteCollectionUri,
                'activity_uri' => $activityUri,
                'state' => $state,
                'updated_at' => $now,
            ], 'id=?', [$existing['id']]);
            return self::byId((string)$existing['id']) ?? $existing;
        }

        $row = [
            'id' => uuid(),
            'user_id' => $userId,
            'remote_actor_id' => $remoteActorId,
            'remote_collection_uri' => $remoteCollectionUri,
            'activity_uri' => $activityUri,
            'state' => $state,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::insertIgnore('collection_feature_authorizations', $row);
        return self::byId($row['id']) ?? $row;
    }

    public static function authorizationUrl(array $user, array $authorization): string
    {
        return actor_url((string)$user['username']) . '/feature-authorizations/' . rawurlencode((string)$authorization['id']);
    }
}
