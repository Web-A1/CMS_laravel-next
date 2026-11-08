<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('adminFieldSaving', (wire, config) => ({
        baseline: JSON.parse(JSON.stringify(config.baseline)),
        revision: config.revision,
        pending: new Set(),
        failed: new Set(),
        busy: false,
        fullBusy: false,
        stopped: false,
        leaving: false,
        guard: false,
        message: '',
        terminalMessage: '',
        loginRequired: false,
        loginUrl: config.loginUrl ?? null,
        running: null,
        intent: false,
        disposed: false,
        cleanups: [],
        allowSubmit: false,
        allowClose: false,
        destination: null,
        submitResolve: null,
        fullSnapshot: null,
        form: null,
        processing: 0,
        processingWait: null,
        processingDone: null,
        preparing: false,
        preparationWait: null,
        preparationDone: null,
        init() {
            this.form = this.$el;
            this.cleanups.push(wire.$interceptRequest(({ request, onRedirect, onError, onFailure }) => {
                const expired = () => {
                    this.loginRequired = true;
                    this.stop('Сессия завершилась. Сохранение не подтверждено. Скопируйте введённый текст перед входом в новой вкладке.');
                };
                onRedirect(({ url, preventDefault }) => {
                    if (!this.alive()) return;
                    const target = new URL(url, window.location.origin);
                    if (target.origin !== window.location.origin || target.pathname !== '/auth') return;
                    preventDefault();
                    expired();
                    // HTTP redirects finish without resolving action promises.
                    request.cancel();
                });
                onError(({ response, preventDefault }) => {
                    if (!this.alive() || ![401, 419, 403, 429].includes(response.status)) return;
                    preventDefault();
                    if ([401, 419].includes(response.status)) expired();
                    else this.stop(response.status === 403
                        ? 'Нет доступа к сохранению. Введённый текст остаётся в форме.'
                        : 'Слишком много запросов. Сохранение не подтверждено. Введённый текст остаётся в форме.');
                });
                onFailure(() => {
                    if (this.alive()) this.stop('Нет связи с сервером. Сохранение не подтверждено. Введённый текст остаётся в форме.');
                });
            }));
            for (const field of [...config.parameters, ...config.manual, ...(config.commands ?? [])]) {
                this.cleanups.push(wire.$watch(this.path(field), () => this.change(field)));
            }
            if (config.preparationPath) {
                const preparation = () => {
                    const state = wire.$get(config.preparationPath);
                    if (state?.error) { this.stop(state.error); return; }
                    if (state?.pending && !this.preparing) {
                        this.preparing = true;
                        this.preparationWait = new Promise((resolve) => { this.preparationDone = resolve; });
                        if (!this.stopped) this.message = 'Изображение готовится…';
                    } else if (!state?.pending && this.preparing) {
                        this.preparing = false;
                        this.preparationDone?.();
                        this.preparationWait = null;
                        this.preparationDone = null;
                        this.schedule();
                    }
                };
                this.cleanups.push(wire.$watch(config.preparationPath, preparation));
                preparation();
            }
            // The native submit still invokes Filament, including its validation and unmount.
            if (config.standalone && !config.existing) {
                this.cleanups.push(wire.$interceptMessage(config.submitMethod, ({ message, onSuccess }) => {
                    const completion = this.submitResolve;
                    if (!this.fullBusy || !completion) return;
                    const index = message.getActions().findIndex((action) => action.name === config.submitMethod);
                    onSuccess(({ payload, onSync }) => {
                        const result = payload.effects.returns?.[index];
                        const meta = payload.effects.returnsMeta?.[index];
                        if (index < 0 || meta?.errors || meta?.status || result?.ok !== true || !result.fields
                            || typeof result.redirect !== 'string' || result.redirect !== payload.effects.redirect) return;
                        const target = new URL(result.redirect, window.location.origin);
                        if (target.origin !== window.location.origin || target.pathname === '/auth') return;
                        // Livewire runs redirect effects before settling action promises.
                        // Sync is the supported boundary after the authoritative snapshot
                        // merges and before location.href can fire beforeunload.
                        onSync(() => {
                            if (!this.alive() || this.stopped || this.submitResolve !== completion) return;
                            this.finishSubmit(result);
                            if (this.dirty() || this.pending.size || this.processing || this.preparing) return;
                            this.guard = false;
                            this.destination = null;
                            this.leaving = true;
                            this.form.inert = true;
                        });
                    });
                }));
            }
            for (const method of new Set([config.submitMethod, ...(config.submitMethods ?? [])])) {
                this.cleanups.push(wire.$intercept(method, ({ action, onFinish }) => {
                const completion = this.submitResolve;
                if (!this.fullBusy || !completion) return;
                // Validation/status metadata can reject the action without an
                // onError callback. Its promise preserves the actual error kind.
                onFinish(() => action.promise.then((result) => {
                    if (this.submitResolve === completion) this.finishSubmit(result);
                }, (error) => {
                    if (this.submitResolve !== completion) return;
                    const conflict = this.conflictMessage(error?.errors);
                    if (conflict) { this.stop(conflict); return; }
                    const validation = error?.status === 422 && error.errors && Object.keys(error.errors).length > 0;
                    this.finishSubmit({ ok: false, validation }, !validation);
                }));
                }));
            }
            if (config.commandActionMethod) {
                this.cleanups.push(wire.$intercept(config.commandActionMethod, ({ action, onFinish }) => {
                    const fields = [...config.manual, ...config.parameters, ...(config.commands ?? [])];
                    const atDispatch = JSON.parse(JSON.stringify(Object.fromEntries(fields.map((field) => [field, this.value(field)]))));
                    onFinish(() => action.promise.then((result) => {
                        if (!this.alive() || this.stopped) return;
                        if (!result?.ok) {
                            if (result?.validation) this.message = result.message || 'Действие не выполнено. Исправьте отмеченные поля.';
                            else this.stop(result?.message || 'Действие не подтверждено. Введённый текст остаётся в форме.');
                            return;
                        }
                        // Support revision is monotonic; an older retry/close ACK
                        // must not replace a newer parameter ACK from the same opening.
                        if (typeof result.revision === 'number' && typeof this.revision === 'number' && result.revision < this.revision) return;
                        for (const [field, value] of Object.entries(result.fields ?? {})) {
                            if (!fields.includes(field)) continue;
                            this.baseline[field] = value;
                            this.failed.delete(field);
                        }
                        for (const [field, value] of Object.entries(result.normalized ?? {})) {
                            if (!Object.hasOwn(result.fields ?? {}, field) || !fields.includes(field)) continue;
                            this.baseline[field] = value;
                            if (this.same(this.value(field), atDispatch[field])) wire.$set(this.path(field), value, false);
                        }
                        for (const field of Object.keys(result.fields ?? {})) {
                            if (!config.parameters.includes(field)) continue;
                            if (!this.changed(field)) this.pending.delete(field);
                            else if (!this.same(this.value(field), atDispatch[field])) this.pending.add(field);
                        }
                        this.revision = result.revision ?? this.revision;
                        this.message = this.preparing ? 'Изображение готовится…' : (this.processing ? 'Загружается файл…' : (this.pending.size ? 'Ожидает сохранения' : 'Сохранено'));
                        this.schedule();
                    }, (error) => {
                        if (!this.alive() || this.stopped) return;
                        const validation = error?.status === 422 && error.errors && Object.keys(error.errors).length > 0;
                        if (validation) this.message = 'Действие не выполнено. Исправьте отмеченные поля.';
                        else this.stop('Действие не подтверждено. Введённый текст остаётся в форме.');
                    }));
                }));
            }
            if (config.uploads) {
                this.listen(this.form, 'form-processing-started', () => {
                    if (!this.processing++) this.processingWait = new Promise((resolve) => { this.processingDone = resolve; });
                    if (!this.stopped) this.message = 'Загружается файл…';
                });
                this.listen(this.form, 'form-processing-finished', () => {
                    this.processing = Math.max(0, this.processing - 1);
                    if (!this.processing) {
                        this.processingDone?.();
                        this.processingWait = null;
                        this.processingDone = null;
                        if (!this.stopped) this.message = this.pending.size ? 'Ожидает сохранения' : '';
                        this.schedule();
                    }
                });
            }
            this.modalId = this.form.closest('[data-fi-modal-id]')?.dataset.fiModalId;
            this.listen(window, 'close-modal', (event) => {
                if (event.detail?.id !== this.modalId) return;
                if (this.allowClose) { this.allowClose = false; return; }
                event.stopImmediatePropagation();
                this.requestLeave(() => {
                    this.allowClose = true;
                    this.form.dispatchEvent(new CustomEvent('close-modal', { bubbles: true, detail: { id: this.modalId } }));
                });
            }, true);
            this.listen(window, 'click', (event) => {
                const link = event.target.closest?.('a[href]');
                if (!link || event.defaultPrevented || event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
                    || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.getAttribute('href').startsWith('#')) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                this.requestLeave(() => window.location.assign(link.href));
            }, true);
            this.listen(window, 'beforeunload', (event) => {
                if (this.leaving) return;
                if (this.dirty() || this.busy || this.fullBusy || this.pending.size || this.failed.size || this.processing || this.preparing) event.preventDefault();
            });
        },
        listen(target, event, callback, capture = false) {
            target.addEventListener(event, callback, capture);
            this.cleanups.push(() => target.removeEventListener(event, callback, capture));
        },
        destroy() {
            this.disposed = true;
            this.processingDone?.();
            this.preparationDone?.();
            for (const cleanup of this.cleanups) cleanup();
        },
        alive() {
            return !this.disposed && wire.$get(config.openingPath) === config.opening;
        },
        stop(message) {
            if (!this.terminalMessage) this.terminalMessage = message;
            this.stopped = true;
            this.message = this.terminalMessage;
            this.processingDone?.();
            this.preparationDone?.();
            if (this.submitResolve) {
                const complete = this.submitResolve;
                this.submitResolve = null;
                this.fullBusy = false;
                this.intent = false;
                this.form.inert = false;
                complete(false);
            }
        },
        value(field) {
            return wire.$get(this.path(field)) ?? null;
        },
        path(field) {
            return config.statePath ? `${config.statePath}.${field}` : field;
        },
        same(left, right) {
            if ((left !== null && typeof left === 'object') || (right !== null && typeof right === 'object')) {
                if (!left || !right || typeof left !== 'object' || typeof right !== 'object' || Array.isArray(left) !== Array.isArray(right)) return false;
                if (Array.isArray(left) && left.length !== right.length) return false;
                const keys = Object.keys(left);
                return keys.length === Object.keys(right).length && keys.every((key) => Object.hasOwn(right, key) && this.same(left[key], right[key]));
            }
            // Empty nullable selects and numeric inputs have equivalent native representations.
            return String(left ?? '') === String(right ?? '');
        },
        changed(field) {
            return !this.same(this.value(field), this.baseline[field]);
        },
        dirty() {
            return this.ordinaryDirty() || this.commandDirty();
        },
        ordinaryDirty() {
            return [...config.manual, ...config.parameters].some((field) => this.changed(field));
        },
        commandDirty() {
            return (config.commands ?? []).some((field) => this.changed(field));
        },
        change(field) {
            if (!this.alive() || this.fullBusy || this.leaving) return;
            if (config.existing && config.parameters.includes(field)) {
                for (const group of config.groups ?? []) {
                    if (group.includes(field)) group.forEach((member) => this.pending.add(member));
                }
                if (this.busy || this.changed(field)) this.pending.add(field);
                else this.pending.delete(field);
                if (!this.stopped) {
                    this.message = this.pending.size ? 'Ожидает сохранения' : '';
                    this.schedule();
                }
            } else if (!this.stopped && this.message === 'Сохранено' && this.changed(field)) {
                this.message = '';
            }
        },
        schedule() {
            if (this.running || this.intent || this.stopped || this.processing || this.preparing || !this.alive()) return;
            this.running = Promise.resolve().then(() => this.flush()).finally(() => {
                this.running = null;
                if (this.pending.size && !this.stopped && !this.intent) this.schedule();
            });
        },
        async flush() {
            while (this.pending.size && !this.stopped && !this.processing && !this.preparing && this.alive()) {
                const fields = [...new Set([...this.failed, ...this.pending])];
                const patch = JSON.parse(JSON.stringify(Object.fromEntries(fields.map((field) => [field, this.value(field)]))));
                const related = [...config.manual, ...config.parameters];
                const manual = JSON.parse(JSON.stringify(Object.fromEntries(related.map((field) => [field, this.value(field)]))));
                const cleanManual = new Set(related.filter((field) => !this.changed(field)));
                this.pending.clear();
                this.busy = true;
                this.message = 'Сохраняется…';
                try {
                    const result = await wire[config.saveMethod](patch, this.revision, config.opening);
                    if (!this.alive() || this.stopped) return;
                    if (!result?.ok) {
                        this.failed = new Set(fields);
                        if (result?.terminal) this.stop(result.message || 'Сохранение остановлено. Скопируйте несохранённый текст перед обновлением страницы.');
                        else {
                            // Watch callbacks can coalesce a return to the old value.
                            // A confirmed validation result may retry only newer input.
                            for (const field of fields) {
                                if (!this.same(this.value(field), patch[field])) this.pending.add(field);
                            }
                            this.message = 'Не сохранено. Исправьте отмеченные поля.';
                        }
                    } else {
                        for (const [field, value] of Object.entries(result.fields)) {
                            this.baseline[field] = value;
                            if (this.same(this.value(field), value)) this.pending.delete(field);
                            else if (this.same(this.value(field), patch[field])) {
                                // Normalize an unchanged submitted value without
                                // treating the server representation as a new intent.
                                wire.$set(this.path(field), value, false);
                                this.pending.delete(field);
                            } else {
                                this.pending.add(field);
                                for (const group of config.groups ?? []) {
                                    if (group.includes(field)) group.forEach((member) => this.pending.add(member));
                                }
                            }
                        }
                        for (const [field, value] of Object.entries(result.derivedFields ?? {})) {
                            if (!related.includes(field)) continue;
                            this.baseline[field] = value;
                            if (cleanManual.has(field) && this.same(this.value(field), manual[field])) wire.$set(this.path(field), value, false);
                        }
                        this.revision = result.revision;
                        this.failed.clear();
                        this.message = this.preparing ? 'Изображение готовится…' : (this.processing ? 'Загружается файл…' : (this.pending.size ? 'Ожидает сохранения' : 'Сохранено'));
                    }
                } catch {
                    if (!this.alive()) return;
                    this.failed = new Set(fields);
                    this.stop('Сохранение не подтверждено. Скопируйте несохранённый текст перед обновлением страницы.');
                } finally {
                    this.busy = false;
                }
            }
        },
        async settle() {
            while (this.alive() && !this.stopped) {
                if (this.processing) { await this.processingWait; continue; }
                if (this.preparing) { await this.preparationWait; continue; }
                if (this.running) { await this.running; continue; }
                if (this.pending.size) { await this.flush(); continue; }
                return;
            }
        },
        submit(event) {
            if (this.allowSubmit) { this.allowSubmit = false; return; }
            event.preventDefault();
            event.stopImmediatePropagation();
            this.manual(event.target);
        },
        async manual(submitForm = null) {
            if (this.intent || this.fullBusy || this.stopped || !this.alive()) return false;
            submitForm ??= config.guardForm ? this.form.querySelector(`#${config.guardForm}`) : this.form;
            if (!submitForm || typeof submitForm.requestSubmit !== 'function') return false;
            this.intent = true;
            await this.settle();
            if (this.stopped || this.failed.size || !this.alive() || !submitForm.reportValidity()) {
                this.intent = false;
                return false;
            }
            this.fullBusy = true;
            this.message = 'Сохраняется…';
            this.form.inert = true;
            // A microtask can still run inside the original submit dispatch, while
            // HTML's "firing submission events" flag suppresses requestSubmit().
            await new Promise((resolve) => setTimeout(resolve, 0));
            await this.settle();
            if (!this.alive() || this.stopped || this.failed.size) {
                this.fullBusy = false;
                this.intent = false;
                this.form.inert = false;
                return false;
            }
            this.message = 'Сохраняется…';
            const completed = new Promise((resolve) => { this.submitResolve = resolve; });
            this.fullSnapshot = JSON.parse(JSON.stringify(Object.fromEntries([...config.manual, ...config.parameters, ...(config.commands ?? [])].map((field) => [field, this.value(field)]))));
            this.allowSubmit = true;
            try {
                submitForm.requestSubmit();
                // Native constraint validation can cancel before any submit event.
                if (this.allowSubmit) {
                    this.allowSubmit = false;
                    this.finishSubmit({ ok: false, validation: true });
                }
            } catch {
                this.allowSubmit = false;
                this.finishSubmit(null, true);
            }
            return completed;
        },
        finishSubmit(result, unknown = false) {
            if (config.standalone && !this.alive()) {
                this.submitResolve?.(false);
                this.submitResolve = null;
                return;
            }
            if (result?.terminal && !this.stopped) { this.stop(result.message || 'Сохранение остановлено. Введённый текст остаётся в форме.'); return; }
            const confirmed = Boolean(result?.ok) && !this.stopped;
            if (confirmed && config.standalone) {
                for (const [field, value] of Object.entries(result.fields ?? {})) this.baseline[field] = value;
                for (const [field, value] of Object.entries(result.normalized ?? {})) {
                    this.baseline[field] = value;
                    if (this.same(this.value(field), this.fullSnapshot?.[field])) wire.$set(this.path(field), value, false);
                }
                this.revision = result.revision ?? this.revision;
                this.failed.clear();
                this.message = 'Сохранено';
            }
            if (!confirmed && !this.stopped) {
                const conflict = this.conflictMessage(config.conflictField ? { [this.path(config.conflictField)]: wire.$errors.get(this.path(config.conflictField)) } : {});
                if (conflict) { this.stop(conflict); return; }
                const validation = !unknown && (result?.validation || wire.$errors.any());
                if (validation) this.message = 'Не сохранено. Исправьте отмеченные поля.';
                else {
                    this.stop('Сохранение не подтверждено. Скопируйте несохранённый текст перед обновлением страницы.');
                    return;
                }
            }
            this.fullBusy = false;
            this.intent = false;
            this.form.inert = false;
            this.submitResolve?.(confirmed);
            this.submitResolve = null;
        },
        conflictMessage(errors) {
            if (!config.conflictField) return '';
            const messages = errors?.[this.path(config.conflictField)];
            return Array.isArray(messages) ? (messages[0] ?? '') : (messages ?? '');
        },
        switchRecord(event) {
            if (!config.switchAction) return;
            const selected = event.target.value;
            event.preventDefault();
            event.stopImmediatePropagation();
            event.target.value = String(config.recordId ?? '');
            if (this.same(selected, config.recordId)) return;
            return this.requestLeave(async () => {
                try {
                    if (!this.disposed) await wire.unmountAction();
                    await wire.mountAction(config.switchAction, { [config.switchArgument]: selected === '' ? null : Number(selected) });
                } catch {
                    if (!this.disposed) {
                        this.leaving = false;
                        this.intent = false;
                        this.form.inert = false;
                        this.stop('Переход не подтверждён. Скопируйте несохранённый текст перед обновлением страницы.');
                    }
                }
            });
        },
        async requestLeave(destination) {
            if (this.intent || this.fullBusy || this.guard || !this.alive()) return;
            this.intent = true;
            // Prevent a clean back from becoming dirty while it waits for ACK.
            this.form.inert = true;
            await this.settle();
            if (!this.alive()) return;
            this.destination = destination;
            if (this.stopped || this.failed.size || this.dirty()) {
                this.guard = true;
                this.form.inert = false;
                this.intent = false;
                this.$nextTick(() => this.form.querySelector('[x-ref="savingStay"]')?.focus());
                return;
            }
            await this.leave(destination);
        },
        async leave(destination) {
            this.leaving = true;
            try { await destination(); }
            catch {
                if (!this.alive()) return;
                this.leaving = false;
                this.intent = false;
                this.form.inert = false;
                this.guard = true;
                this.stop('Переход не подтверждён. Введённый текст остаётся в форме.');
            }
        },
        async resolveGuard(choice) {
            if (choice === 'stay') {
                this.guard = false;
                this.destination = null;
                return;
            }
            if (choice === 'save') {
                if (!this.ordinaryDirty()) return;
                const destination = this.destination;
                if (await this.manual()) {
                    if (this.leaving) return;
                    if (config.standalone && this.ordinaryDirty()) {
                        this.guard = true;
                        this.$nextTick(() => this.form.querySelector('[x-ref="savingStay"]')?.focus());
                        return;
                    }
                    if (this.commandDirty()) {
                        this.message = 'Обычные поля сохранены. Ввод команды остаётся несохранённым.';
                        this.$nextTick(() => this.form.querySelector('[x-ref="savingStay"]')?.focus());
                    } else await this.leave(destination);
                }
                return;
            }
            if (choice === 'discard') {
                if (this.intent || this.fullBusy || !this.alive()) return;
                const knownStopped = this.stopped;
                const hadWork = Boolean(this.running || this.busy || this.pending.size || this.processing || this.preparing);
                this.intent = true;
                this.form.inert = true;
                await this.settle();
                if (!this.alive()) return;
                if (!knownStopped && hadWork && (this.stopped || this.failed.size)) {
                    // The decision preceded this failure; show its outcome before
                    // accepting another explicit discard of the still-live draft.
                    this.intent = false;
                    this.form.inert = false;
                    this.$nextTick(() => this.form.querySelector('[x-ref="savingStay"]')?.focus());
                    return;
                }
                this.guard = false;
                await this.leave(this.destination);
            }
        },
    }));
});
</script>
