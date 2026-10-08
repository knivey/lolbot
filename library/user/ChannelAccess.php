<?php

namespace library\user;

/*
 * Channel-scoped access checks for scripts that gate by flag in the
 * channel a command ran in (e.g. the .set/.unset settings commands).
 *
 * Semantics are the acl middleware's channel mode expressed as a pure
 * predicate over a resolved user: the Channel row for the bot in that
 * channel must exist (a channel the bot isn't configured for denies),
 * the check set is the user's network flags UNION their channel_flags
 * grant for THAT channel, and the Access before-hook (superadmin)
 * short-circuits — the owner can never be locked out of a channel they
 * can already speak in, mirroring Acl::middleware()'s channel path.
 */
class ChannelAccess
{
    public static function passesInChannel(UserSystem $us, object $user, string $chan, string $flag): bool
    {
        $channel = $us->channelByName($chan);
        if ($channel === null) {
            return false;
        }
        if (Access::beforeAllows($user)) {
            return true;
        }
        $grant = $us->repos->channelFlags->findForChannelUser($channel->id, (int) ($user->id ?? 0));
        $union = array_merge(
            Access::flagArray($user),
            $grant !== null ? Access::flagArray($grant) : [],
        );
        return Flags::passes($union, $flag);
    }
}
