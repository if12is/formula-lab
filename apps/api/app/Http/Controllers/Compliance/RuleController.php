<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compliance\StoreRuleRequest;
use App\Http\Requests\Compliance\UpdateRuleRequest;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use App\Services\Compliance\RuleService;
use Illuminate\Http\RedirectResponse;

class RuleController extends Controller
{
    public function __construct(private RuleService $ruleService) {}

    public function store(StoreRuleRequest $request, RuleSet $ruleSet): RedirectResponse
    {
        try {
            $this->ruleService->add($ruleSet, $request->ruleDocument(), $request->ruleMessage(), $request->ruleActive());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['rule.id' => $exception->getMessage()]);
        }

        return back()->with('success', __('compliance.rules.added'));
    }

    public function update(UpdateRuleRequest $request, RuleSet $ruleSet, ComplianceRule $rule): RedirectResponse
    {
        try {
            $this->ruleService->update($rule, $request->ruleDocument(), $request->ruleMessage(), $request->ruleActive());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['rule.id' => $exception->getMessage()]);
        }

        return back()->with('success', __('compliance.rules.updated'));
    }

    public function destroy(RuleSet $ruleSet, ComplianceRule $rule): RedirectResponse
    {
        try {
            $this->ruleService->remove($rule);
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('compliance.rules.removed'));
    }
}
