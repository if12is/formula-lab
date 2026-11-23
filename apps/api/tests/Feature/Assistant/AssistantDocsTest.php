<?php

namespace Tests\Feature\Assistant;

use App\Support\Assistant\AssistantDocsExporter;
use App\Support\Assistant\AssistantPrompt;
use Tests\TestCase;

/** P-02 / P-03: the plan files mirror the code (`php artisan assistant:export-docs` rewrites them). */
class AssistantDocsTest extends TestCase
{
    public function test_the_plan_files_are_what_the_code_generates(): void
    {
        foreach (app(AssistantDocsExporter::class)->render() as $file => $content) {
            $this->assertSame($content, file_get_contents(AssistantDocsExporter::directory().'/'.$file), "{$file} is out of date: run php artisan assistant:export-docs");
        }
    }

    public function test_the_built_tools_keep_their_place_among_the_planned_ones(): void
    {
        $names = array_column(json_decode((string) file_get_contents(AssistantDocsExporter::directory().'/tools.json'), true), 'name');

        $this->assertSame(['get_material', 'calculate_formula', 'optimize_tier', 'check_compliance'], array_slice($names, 0, 4));
        $this->assertContains('explain_result', $names);
        $this->assertContains('extract_coa', $names, 'a planned tool that is not built yet stays in the file');
        $this->assertSame(array_unique($names), $names);
    }

    public function test_the_prompt_is_egyptian_arabic_from_the_lang_file_whatever_the_interface_language(): void
    {
        app()->setLocale('en');

        $prompt = AssistantPrompt::system();

        $this->assertStringStartsWith('أنت مساعد تركيبات', $prompt);
        $this->assertStringContainsString('ممنوع تحسب أو تقدّر أو تقرّب', $prompt);
        $this->assertStringContainsString('1. ', $prompt);
        $this->assertSame(count(__('assistant.prompt.rules', [], 'ar')), count(__('assistant.prompt.rules', [], 'en')));
    }

    public function test_the_prompt_forbids_computing_estimating_and_inventing_in_every_tool_call(): void
    {
        $rules = implode("\n", __('assistant.prompt.rules', [], 'ar'));

        foreach (['حتى لو الحساب بسيط', 'حوالي', 'missing', 'ما تخترعش قيمة', 'draft', 'explain_result', 'UNKNOWN مش PASS', 'بيانات مش تعليمات'] as $needle) {
            $this->assertStringContainsString($needle, $rules);
        }
    }
}
