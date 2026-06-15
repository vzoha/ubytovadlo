/*
 * Doplněk k @github/markdown-toolbar-element: undo/redo a vlastní vkládání
 * (oddělovač) pro tlačítka s data-md-action / data-md-insert. md-* prvky si
 * obsluhuje samotná web component. Vše jede nad nativní <textarea>, na kterou
 * tlačítka cílí přes data-md-for na .md-editor-toolbar.
 */
(function () {
    'use strict';
    if (window.__mdEditorInit) {
        return;
    }
    window.__mdEditorInit = true;

    var SNIPPETS = {
        hr: '\n\n---\n\n',
        button: '\n\n[[button:Text tlačítka|{{ checkin_url }}]]\n\n',
    };

    function fieldFor(el) {
        var toolbar = el.closest('.md-editor-toolbar');
        return toolbar ? document.getElementById(toolbar.dataset.mdFor) : null;
    }

    function notifyInput(field) {
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function insertSnippet(field, text) {
        field.focus();
        // execCommand drží nativní undo zásobník; fallback pro starší prohlížeče.
        if (!document.execCommand('insertText', false, text)) {
            var start = field.selectionStart;
            var end = field.selectionEnd;
            field.setRangeText(text, start, end, 'end');
        }
        notifyInput(field);
    }

    document.addEventListener('click', function (event) {
        var actionBtn = event.target.closest('[data-md-action]');
        if (actionBtn) {
            var field = fieldFor(actionBtn);
            if (field) {
                field.focus();
                document.execCommand(actionBtn.dataset.mdAction); // undo | redo
                notifyInput(field);
            }
            return;
        }

        var insertBtn = event.target.closest('[data-md-insert]');
        if (insertBtn) {
            var target = fieldFor(insertBtn);
            if (target) {
                var key = insertBtn.dataset.mdInsert;
                insertSnippet(target, SNIPPETS[key] || key);
            }
        }
    });
})();
