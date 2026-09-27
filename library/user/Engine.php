<?php

namespace library\user;

/*
 * A single strategy for resolving a nick to a user id (account-tag,
 * vhost, whox, hostmask, manual). Engines return null when they cannot
 * resolve so the next engine in the chain gets a chance.
 */

interface Engine
{
    /**
     * Provenance tag recorded in the identity cache for hits from this
     * engine, e.g. 'account-tag'. Every engine overrides this.
     */
    public const PROVENANCE = '';

    public function resolve(ResolveContext $ctx): ?int;
}
