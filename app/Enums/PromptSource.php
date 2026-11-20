<?php

namespace App\Enums;

/**
 * Where a prompt came from. Regeneration only replaces generated prompts
 * that nobody has edited.
 */
enum PromptSource: string
{
    case Generated = 'generated';
    case Custom = 'custom';
    /** Copied from the free audit the trial came from (inactive; keeps its answers visible). */
    case Audit = 'audit';
}
