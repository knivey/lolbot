<?php

namespace library\user\engines;

use library\user\Engine;
use library\user\ResolveContext;
use library\user\UserHostmaskRepo;

/*
 * Resolves via admin-managed hostmask globs: each stored mask is
 * matched case-insensitively against the full nick!ident@host string
 * (Nicks::h2n globToRegex precedent), first match wins. Whether a mask
 * pins the nick, the ident or only the host is the admin's choice; the
 * engine just does the glob match.
 */

class HostmaskEngine implements Engine
{
    public const PROVENANCE = 'hostmask';

    public function __construct(private UserHostmaskRepo $maskRepo)
    {
    }

    public function resolve(ResolveContext $ctx): ?int
    {
        $identHost = $ctx->identHost;
        if ($identHost === null) {
            return null;
        }
        $full = $ctx->nick . '!' . $identHost;
        foreach ($this->maskRepo->findForHost($ctx->networkId, $identHost) as $row) {
            if (preg_match(\knivey\tools\globToRegex($row['mask']) . 'i', $full)) {
                return (int) $row['user_id'];
            }
        }
        return null;
    }
}
