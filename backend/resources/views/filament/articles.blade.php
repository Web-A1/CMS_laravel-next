<x-filament-panels::page
    data-ui="article-management"
    x-data="articleEditor($wire)"
    x-on:click.capture="intercept($event)"
>
    @if ($this->editorOpen)
        <form id="article-editor-form" x-on:submit.prevent="save()" x-bind:inert="fullBusy" data-ui="article-editor" class="fi-sc fi-sc-has-gap">
            {{ $this->form }}
            <div class="article-editor-actions" data-ui="article-editor.actions">
                <x-filament::button type="submit" x-bind:disabled="fullBusy || stopped">Сохранить</x-filament::button>
                <x-filament::button type="button" color="gray" x-on:click="preview()" x-bind:disabled="previewBusy || fullBusy || uploads > 0" data-ui="article-preview.open">Предпросмотр</x-filament::button>
                @if ($this->entryId !== null)
                    {{ $this->deleteAction }}
                @endif
            </div>
        </form>
        <dialog x-ref="articlePreview" data-ui="article-preview" class="article-preview-dialog" style="width: min(100% - 2rem, 64rem); height: 90vh; padding: 1rem; border: 0; border-radius: .75rem;">
            <x-filament::button type="button" color="gray" x-on:click="$refs.articlePreview.close()">Закрыть предпросмотр</x-filament::button>
            <iframe title="Предпросмотр статьи" x-bind:srcdoc="previewHtml" sandbox="allow-same-origin allow-scripts allow-presentation" allow="fullscreen" style="width: 100%; height: calc(100% - 3rem); border: 0; background: white;"></iframe>
        </dialog>
        <p x-show="previewError" x-text="previewError" role="alert" data-ui="article-preview.error"></p>
        <x-filament-actions::modals />
    @else
        <div data-ui="article-list">{{ $this->table }}</div>
    @endif
</x-filament-panels::page>

@script
<script>
    Alpine.data('articleEditor', (wire) => ({
        busy: false,
        fullBusy: false,
        coverBusy: false,
        stopped: false,
        message: '',
        pending: new Set(),
        failed: new Set(),
        running: null,
        intent: null,
        uploads: 0,
        opening: crypto.randomUUID(),
        previewBusy: false,
        previewHtml: '',
        previewError: '',
        init() {
            if (!wire.editorOpen) return;
            for (const field of ['status', 'subject_id', 'content_author_id', 'published_at', 'cover_path']) {
                wire.$watch(`data.${field}`, () => {
                    if (this.fullBusy || (field === 'cover_path' && this.coverBusy)) return;
                    if (!wire.entryId) return;
                    this.pending.add(field);
                    if (!this.stopped) this.message = 'Ожидает сохранения';
                    // Date input can update its model while a date is still being entered.
                    if (field !== 'published_at') this.schedule();
                });
            }
            for (const field of ['title', 'excerpt', 'body', 'seo_title', 'seo_description', 'cover_alt', 'slug', 'recommendation_article_ids', 'recommendation_faq_ids']) {
                wire.$watch(`data.${field}`, () => { if (this.message === 'Сохранено') this.message = ''; });
            }
            this.$el.addEventListener('change', (event) => {
                if (event.target.id === 'form.published_at') this.schedule();
            });
            this.$el.addEventListener('livewire-upload-start', () => { this.uploads++; if (!this.stopped) this.message = 'Загружается файл…'; });
            const uploadFinished = () => { this.uploads = Math.max(0, this.uploads - 1); this.schedule(); };
            this.$el.addEventListener('livewire-upload-finish', uploadFinished);
            this.$el.addEventListener('livewire-upload-cancel', uploadFinished);
            this.$el.addEventListener('livewire-upload-error', () => { this.uploads = Math.max(0, this.uploads - 1); if (!this.stopped) this.message = 'Файл не загружен. Выберите его заново.'; });
            this.unload = (event) => {
                if (wire?.__instance?.effects?.redirect) return;
                if (this.busy || this.pending.size || this.failed.size || this.uploads) {
                    event.preventDefault(); event.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this.unload);
        },
        destroy() { window.removeEventListener('beforeunload', this.unload); },
        changed(field) {
            return JSON.stringify(wire.data?.[field] ?? null) !== JSON.stringify(wire.savedForm?.[field] ?? null);
        },
        schedule() {
            if (this.busy || this.stopped || this.intent || this.uploads || !wire.entryId) return;
            queueMicrotask(() => { if (!this.busy && !this.intent && !this.stopped && !this.uploads) this.running = this.drain(); });
        },
        async drain() {
            if (this.busy || !this.pending.size) return;
            const fields = [...new Set([...this.failed, ...this.pending])];
            this.pending.clear();
            const opening = this.opening;
            const id = wire.entryId;
            this.busy = true;
            this.coverBusy = fields.includes('cover_path');
            this.message = 'Сохраняется…';
            try {
                const result = await wire.saveParameters(fields, wire.revision);
                if (opening !== this.opening || id !== wire.entryId) return;
                if (!result?.ok) {
                    this.failed = new Set(fields);
                    this.stopped = Boolean(result?.conflict);
                    this.message = result?.conflict ? 'Статья уже изменена. Скопируйте несохранённый текст перед обновлением страницы.' : 'Не сохранено. Исправьте отмеченные поля.';
                } else {
                    this.failed.clear();
                    this.message = this.pending.size ? 'Ожидает сохранения' : 'Сохранено';
                }
            } catch {
                this.failed = new Set(fields);
                this.stopped = true;
                this.message = 'Сохранение не подтверждено. Скопируйте несохранённый текст перед обновлением страницы.';
            } finally {
                this.busy = false;
                this.coverBusy = false;
            }
            if (this.pending.size) this.schedule();
        },
        async save() {
            if (this.intent || this.fullBusy || this.stopped) return;
            this.intent = 'save';
            if (this.running) await this.running;
            if (this.stopped || this.uploads) { this.intent = null; return; }
            this.fullBusy = true;
            this.message = 'Сохраняется…';
            try {
                const result = await wire.save();
                if (!result?.ok) { this.message = 'Не сохранено. Проверьте поля статьи.'; return; }
                this.pending.clear(); this.failed.clear();
                this.message = 'Сохранено';
                this.fullBusy = false; this.intent = null;
            } catch {
                this.stopped = true;
                this.message = 'Сохранение не подтверждено. Скопируйте текст перед обновлением страницы.';
            } finally { this.fullBusy = false; this.intent = null; }
        },
        async preview() {
            if (this.previewBusy || this.fullBusy || this.uploads) return;
            this.previewBusy = true;
            this.previewError = '';
            try {
                const result = await wire.previewDocument();
                if (!result?.ok) { this.previewError = 'Не удалось открыть предпросмотр. Проверьте отмеченные поля.'; return; }
                this.previewHtml = result.html;
                this.$refs.articlePreview.showModal();
            } catch { this.previewError = 'Предпросмотр недоступен. Попробуйте ещё раз.'; }
            finally { this.previewBusy = false; }
        },
        async action(name) {
            if (this.intent || this.fullBusy || this.uploads) return;
            this.intent = name;
            if (this.running) await this.running;
            while (this.pending.size && !this.stopped) {
                await this.drain();
                if (this.failed.size) break;
            }
            if (this.stopped && name !== 'back') { this.intent = null; return; }
            if (name === 'back') this.fullBusy = true;
            try { await wire.mountAction(name); }
            finally {
                this.fullBusy = name === 'back' && Boolean(wire?.__instance?.effects?.redirect);
                this.intent = null;
            }
        },
        intercept(event) {
            if (!wire.editorOpen || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button) return;
            const breadcrumb = event.target.closest('.fi-breadcrumbs a');
            const action = event.target.closest('[data-article-action]');
            if (breadcrumb || action) {
                event.preventDefault(); event.stopImmediatePropagation();
                this.action(breadcrumb ? 'back' : action.dataset.articleAction);
            }
        },
    }));
</script>
@endscript
