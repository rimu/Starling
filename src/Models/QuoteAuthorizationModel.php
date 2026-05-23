<?php
declare(strict_types=1);

namespace App\Models;

class QuoteAuthorizationModel
{
    public static function byId(string $id): ?array
    {
        return DB::one('SELECT * FROM quote_authorizations WHERE id=? LIMIT 1', [$id]);
    }

    public static function acceptedByIdForUser(string $id, string $userId): ?array
    {
        return DB::one(
            "SELECT * FROM quote_authorizations
             WHERE id=? AND user_id=? AND state='accepted'
             LIMIT 1",
            [$id, $userId]
        );
    }

    public static function byUserAndActivity(string $userId, string $activityUri): ?array
    {
        return DB::one(
            'SELECT * FROM quote_authorizations WHERE user_id=? AND activity_uri=? LIMIT 1',
            [$userId, $activityUri]
        );
    }

    public static function byQuotedStatusAndQuote(string $quotedStatusId, string $quoteUri): ?array
    {
        return DB::one(
            'SELECT * FROM quote_authorizations WHERE quoted_status_id=? AND quote_uri=? LIMIT 1',
            [$quotedStatusId, $quoteUri]
        );
    }

    public static function upsertDecision(
        string $userId,
        string $quotedStatusId,
        string $quotedUri,
        string $remoteActorId,
        string $quoteUri,
        string $activityUri,
        string $state
    ): array {
        $existing = self::byUserAndActivity($userId, $activityUri)
            ?? self::byQuotedStatusAndQuote($quotedStatusId, $quoteUri);
        $now = now_iso();

        if ($existing) {
            DB::update('quote_authorizations', [
                'quoted_status_id' => $quotedStatusId,
                'quoted_uri'       => $quotedUri,
                'remote_actor_id'  => $remoteActorId,
                'quote_uri'        => $quoteUri,
                'activity_uri'     => $activityUri,
                'state'            => $state,
                'updated_at'       => $now,
            ], 'id=?', [$existing['id']]);
            return self::byId((string)$existing['id']) ?? $existing;
        }

        $row = [
            'id'               => uuid(),
            'user_id'          => $userId,
            'quoted_status_id' => $quotedStatusId,
            'quoted_uri'       => $quotedUri,
            'remote_actor_id'  => $remoteActorId,
            'quote_uri'        => $quoteUri,
            'activity_uri'     => $activityUri,
            'state'            => $state,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        DB::insertIgnore('quote_authorizations', $row);
        return self::byId($row['id']) ?? $row;
    }

    public static function authorizationUrl(array $user, array $authorization): string
    {
        return actor_url((string)$user['username']) . '/quote-authorizations/' . rawurlencode((string)$authorization['id']);
    }
}
