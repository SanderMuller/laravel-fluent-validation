<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

/**
 * boost-core configuration.
 *
 * Re-run `vendor/bin/boost install` to update agents/vendors
 * interactively, or hand-edit this file. After changes run
 * `vendor/bin/boost sync`.
 *
 * Docs: https://github.com/sandermuller/boost-core
 */
return BoostConfig::configure()
    ->withDisabledEmitters([])
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::COPILOT,
        Agent::CODEX,
    ])
    ->withAllowedVendors([
        'sandermuller/boost-core',
        'sandermuller/boost-skills',
        'sandermuller/package-boost-laravel',
        'sandermuller/package-boost-php',
        'stolt/lean-package-validator',
    ])
    ->withTags([
        Tag::Php,
        Tag::Laravel,
        Tag::Github,
        'release-automation',
        Tag::Pest,
    ]);
