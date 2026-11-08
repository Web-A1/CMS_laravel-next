<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait HasManualModalSaving
{
    #[Locked]
    public string $manualOpening = '';

    #[Locked]
    public array $manualSaved = [];

    #[Locked]
    public ?array $manualSnapshot = null;

    protected function beginManualModal(?Schema $schema, ?Model $record = null, array $fields = []): void
    {
        $current = $record?->fresh();
        abort_if($record !== null && $current === null, 404);
        $this->manualSnapshot = $current?->only($fields);
        $this->beginOperation($schema, $this->manualSnapshot ?? []);
        $this->manualOpening = (string) Str::uuid();
        $raw = $schema?->getRawState() ?? [];
        $this->manualSaved = $raw instanceof Arrayable ? $raw->toArray() : $raw;
    }

    protected function manualModalAction(Action $action): Action
    {
        return $action->extraModalWindowAttributes(fn (Action $action) => [
            'x-data' => 'adminFieldSaving($wire, '.Js::from([
                'statePath' => 'mountedActions.'.$action->getNestingIndex().'.data',
                'baseline' => $this->manualSaved,
                'opening' => $this->manualOpening,
                'openingPath' => 'manualOpening',
                'revision' => null,
                'existing' => $this->manualSnapshot !== null,
                'parameters' => [],
                'manual' => array_keys($this->manualSaved),
                'saveMethod' => null,
                'submitMethod' => 'callMountedAction',
            ])->toHtml().')',
            'x-on:submit.capture' => 'submit($event)',
            'data-ui' => 'manual-modal-editor',
        ])->modalCancelAction(fn (Action $action) => $action->extraAttributes([
            'x-on:click.capture' => '$event.preventDefault(); $event.stopImmediatePropagation(); requestLeave(() => { if (!disposed) close() })',
        ]));
    }

    protected function manualSaveHint(string $field): SchemaView
    {
        return SchemaView::make('filament.field-saving.hint')->viewData(['field' => $field]);
    }

    protected function manualSavingStatus(): SchemaView
    {
        return SchemaView::make('filament.field-saving.status')->visibleJs('message || guard');
    }

    protected function completeManualModal(callable $operation): array
    {
        $this->completeOperation($operation);

        return ['ok' => true];
    }
}
