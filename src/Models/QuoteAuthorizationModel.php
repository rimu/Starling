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

    public static function byActivityUri(string $activityUri): ?array
    {
        return DB::one(
            'SELECT * FROM quote_authorizations WHERE activity_uri=? LIMIT 1',
            [$activityUri]
        );
    }

    public static function byQuotedStatusAndQuote(string $quotedStatusId, string $quoteUri): ?array
    {
        return DB::one(
            'SELECT * FROM quote_authorizations WHERE quoted_status_id=? AND quote_uri=? LIMIT 1',
            [$quotedStatusId, $quoteUri]
        );
    }

    public static function acceptedForQuote(string $quoteUri, string $quotedStatusId): ?array
    {
        return DB::one(
            "SELECT * FROM quote_authorizations
             WHERE quote_uri=? AND quoted_status_id=? AND state='accepted'
             LIMIT 1",
            [$quoteUri, $quotedStatusId]
        );
    }

    public static function requestActivityUri(array $quoteStatus, array $quotedStatus): string
    {
        $quoteUri = (string)($quoteStatus['uri'] ?? '');
        $quotedUri = (string)($quotedStatus['uri'] ?? '');
        return $quoteUri . '#quote-request/' . hash('sha256', $quotedUri);
    }

    public static function upsertDecision(
        string $userId,
        string $quotedStatusId,
        string $quotedUri,
        string $remoteActorId,
        string $quoteUri,
        string $activityUri,
        string $state,
        string $authorizationUri = ''
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
                'authorization_uri'=> $authorizationUri,
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
            'authorization_uri'=> $authorizationUri,
            'state'            => $state,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        DB::insertIgnore('quote_authorizations', $row);
        return self::byId($row['id']) ?? $row;
    }

    public static function markAccepted(string $id, string $authorizationUri): ?array
    {
        DB::update('quote_authorizations', [
            'state' => 'accepted',
            'authorization_uri' => $authorizationUri,
            'updated_at' => now_iso(),
        ], 'id=?', [$id]);
        return self::byId($id);
    }

    public static function markRejected(string $id): ?array
    {
        DB::update('quote_authorizations', [
            'state' => 'rejected',
            'authorization_uri' => '',
            'updated_at' => now_iso(),
        ], 'id=?', [$id]);
        return self::byId($id);
    }

    private static function quotedStatusForAuthorization(array $status): ?array
    {
        $quoteId = (string)($status['quote_of_id'] ?? '');
        if ($quoteId === '') return null;

        $quoted = StatusModel::byId($quoteId);
        if ($quoted && !empty($quoted['reblog_of_id'])) {
            $orig = StatusModel::byId((string)$quoted['reblog_of_id']);
            if ($orig) $quoted = $orig;
        }
        if (!$quoted || !in_array((string)($quoted['visibility'] ?? ''), ['public', 'unlisted'], true)) {
            return null;
        }

        return $quoted;
    }

    public static function ensureOutgoingForLocalQuote(array $quoteAuthor, array $status): void
    {
        if ((int)($status['local'] ?? 0) !== 1) return;

        $quoted = self::quotedStatusForAuthorization($status);
        if (!$quoted) return;

        $quotedOwnerId = (string)($quoted['user_id'] ?? '');
        if ($quotedOwnerId === '' || $quotedOwnerId === (string)$quoteAuthor['id']) return;

        $activityUri = self::requestActivityUri($status, $quoted);
        $quoteActorUrl = actor_url((string)$quoteAuthor['username']);

        $localQuotedOwner = UserModel::byId($quotedOwnerId);
        if ($localQuotedOwner) {
            $authorization = self::upsertDecision(
                (string)$localQuotedOwner['id'],
                (string)$quoted['id'],
                (string)$quoted['uri'],
                $quoteActorUrl,
                (string)$status['uri'],
                $activityUri,
                'accepted'
            );
            self::markAccepted(
                (string)$authorization['id'],
                self::authorizationUrl($localQuotedOwner, $authorization)
            );
            return;
        }

        if (!str_starts_with($quotedOwnerId, 'http')) return;
        $remoteQuotedOwner = DB::one('SELECT * FROM remote_actors WHERE id=? LIMIT 1', [$quotedOwnerId])
            ?? RemoteActorModel::fetch($quotedOwnerId);
        if (!$remoteQuotedOwner) return;

        $authorization = self::byQuotedStatusAndQuote((string)$quoted['id'], (string)$status['uri']);
        if (($authorization['state'] ?? '') === 'accepted' && trim((string)($authorization['authorization_uri'] ?? '')) !== '') {
            return;
        }
        if (!$authorization || ($authorization['state'] ?? '') !== 'pending') {
            $authorization = self::upsertDecision(
                (string)$quoteAuthor['id'],
                (string)$quoted['id'],
                (string)$quoted['uri'],
                $quotedOwnerId,
                (string)$status['uri'],
                $activityUri,
                'pending'
            );
        }

        \App\ActivityPub\Delivery::queueToActorInbox(
            $quoteAuthor,
            $remoteQuotedOwner,
            \App\ActivityPub\Builder::quoteRequest($status, $quoteAuthor, $quoted)
        );
    }

    public static function queueMissingOutgoingRequests(int $limit = 50): int
    {
        $limit = max(1, min(500, $limit));
        $rows = DB::all(
            "SELECT s.*
               FROM statuses s
               JOIN statuses q ON q.id=s.quote_of_id
              WHERE s.local=1
                AND s.quote_of_id IS NOT NULL
                AND s.quote_of_id<>''
                AND s.visibility IN ('public','unlisted')
                AND (s.expires_at IS NULL OR s.expires_at='' OR s.expires_at>?)
                AND q.user_id LIKE 'http%'
                AND NOT EXISTS (
                    SELECT 1 FROM quote_authorizations qa
                     WHERE qa.quote_uri=s.uri
                       AND qa.quoted_status_id=q.id
                       AND qa.state IN ('pending','accepted','rejected')
                )
              ORDER BY s.created_at DESC
              LIMIT ?",
            [now_iso(), $limit]
        );

        $queued = 0;
        foreach ($rows as $status) {
            $author = UserModel::byId((string)$status['user_id']);
            if (!$author || !empty($author['is_suspended'])) continue;
            try {
                self::ensureOutgoingForLocalQuote($author, $status);
                $queued++;
            } catch (\Throwable $e) {
                error_log('[Starling] quote authorization replay skipped: ' . $e->getMessage());
            }
        }

        return $queued;
    }

    public static function authorizationUrl(array $user, array $authorization): string
    {
        return actor_url((string)$user['username']) . '/quote-authorizations/' . rawurlencode((string)$authorization['id']);
    }
}
