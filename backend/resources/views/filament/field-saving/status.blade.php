<div data-ui="field-saving.feedback" x-show="message || guard" x-cloak>
    <p role="status" aria-live="polite" x-text="message" x-show="message" x-cloak></p>
    <x-filament::link x-show="loginRequired && loginUrl" x-cloak x-bind:href="loginUrl" target="_blank" rel="noopener">Войти в новой вкладке</x-filament::link>
    <div x-show="guard" x-cloak role="group" aria-label="Несохранённые изменения" data-ui="field-saving.guard">
        <p x-text="commandDirty() ? (ordinaryDirty() ? 'Сохранить обычные поля? Ввод команды останется в форме.' : 'Есть несохранённый ввод команды. Уйти без выполнения команды?') : 'Сохранить изменения перед выходом?'"></p>
        <x-filament::button type="button" size="sm" x-show="ordinaryDirty()" x-cloak x-on:click="resolveGuard('save')" x-bind:disabled="stopped || fullBusy">Сохранить</x-filament::button>
        <x-filament::button type="button" size="sm" color="gray" x-on:click="resolveGuard('discard')">Уйти без сохранения</x-filament::button>
        <x-filament::button type="button" size="sm" color="gray" x-ref="savingStay" x-on:click="resolveGuard('stay')">Продолжить редактирование</x-filament::button>
    </div>
</div>
