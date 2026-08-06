<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard cache
    |--------------------------------------------------------------------------
    |
    | The cortex database is remote, so a dashboard request spends most of its
    | time waiting on the network. These TTLs (in seconds) decide how long the
    | derived results are reused before the queries run again. Set any of them
    | to 0 to bypass the cache for that kind of lookup.
    |
    | The cache always uses the file store: CACHE_STORE points at the same
    | remote Postgres we are trying to avoid, so the default store would add a
    | round trip rather than remove one.
    |
    */

    // Aggregates (counts, trend, engagement, top-N). Follows how often the
    // ingest pipeline writes — lower it if the dashboard must feel live.
    'aggregate_cache_ttl' => (int) env('CORTEX_AGGREGATE_CACHE_TTL', 300),

    // Filter dropdown contents. New keywords and regions appear far more
    // rarely than new posts.
    'filter_cache_ttl' => (int) env('CORTEX_FILTER_CACHE_TTL', 3600),

    // Which platform tables exist in a client's schema. This is DDL: it only
    // changes when a client is onboarded onto a new platform.
    'schema_cache_ttl' => (int) env('CORTEX_SCHEMA_CACHE_TTL', 3600),

];
