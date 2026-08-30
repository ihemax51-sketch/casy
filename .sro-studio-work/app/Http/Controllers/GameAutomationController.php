<?php

namespace App\Http\Controllers;

use App\Services\GameAutomationCatalog;
use App\Services\GameAutomationService;
use App\Services\OperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class GameAutomationController extends Controller
{
    public function run(
        Request $request,
        string $module,
        string $action,
        GameAutomationCatalog $catalog,
        GameAutomationService $automations,
        OperationService $operations,
    ): RedirectResponse {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        if ($profile->connection_status !== 'connected') {
            return back()->withErrors(['automation' => 'Connect the active server before running an automation.']);
        }

        try {
            $definition = $catalog->get($action);
            if ($definition['module'] !== $module) {
                throw new RuntimeException('This automation does not belong to the selected studio.');
            }
            $rules = ['intent' => ['required', 'in:preview,execute']];
            foreach ($definition['fields'] as $field) {
                $rules[$field['name']] = explode('|', $field['rules']);
            }
            $intent = $definition['readOnly'] ? 'preview' : (string) $request->input('intent', 'preview');
            if ($intent === 'execute') {
                $rules['owner_password'] = ['required', 'string'];
                if ($definition['risk'] === 'critical') {
                    $rules['backup_reference'] = ['required', 'string', 'max:255'];
                    $rules['backup_at'] = ['required', 'date'];
                }
            }
            $validator = Validator::make(array_merge($request->all(), ['intent' => $intent]), $rules);
            if ($validator->fails()) {
                return back()->withErrors($validator)->withInput($request->except('owner_password'))->with('automation_action', $action);
            }
            $input = $validator->validated();

            if ($intent === 'execute' && ! Hash::check((string) $input['owner_password'], (string) auth()->user()->password)) {
                return back()->withErrors(['owner_password' => 'Owner password confirmation failed.'])->withInput($request->except('owner_password'))->with('automation_action', $action);
            }
            if ($intent === 'execute' && $definition['risk'] === 'critical') {
                $backupAt = Carbon::parse($input['backup_at']);
                if (now()->lt($backupAt) || now()->diffInHours($backupAt) > 24) {
                    return back()->withErrors(['backup_at' => 'Critical actions require backup proof from the last 24 hours.'])->withInput($request->except('owner_password'))->with('automation_action', $action);
                }
            }

            $context = array_diff_key($input, array_flip(['owner_password']));
            $operation = $operations->begin('automation.'.$action, auth()->id(), $profile->id, [
                'execution_mode' => $definition['readOnly'] ? 'lookup' : $intent,
                'query_references' => $definition['queryRefs'],
                'input' => $context,
                'backup_reference' => $input['backup_reference'] ?? null,
            ], $definition['risk']);
            try {
                $result = $automations->run($profile, $action, $input, $intent === 'execute');
                $operations->complete($operation, $result['after'] ?? [], ['result' => $result]);
            } catch (Throwable $exception) {
                $operations->fail($operation, $exception->getMessage());
                throw $exception;
            }

            return back()->withInput($request->except(['owner_password']))
                ->with('automation_action', $action)
                ->with('automation_result', $result)
                ->with('success', $definition['readOnly'] ? 'Lookup completed.' : ($intent === 'execute' ? 'Automation executed successfully.' : 'Preview completed. No rows were changed.'));
        } catch (Throwable $exception) {
            return back()->withErrors(['automation' => $exception->getMessage()])->withInput($request->except('owner_password'))->with('automation_action', $action);
        }
    }
}
