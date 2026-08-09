(() => {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    const scrim = document.getElementById('scrim');
    const mobileMore = document.getElementById('mobileMore');
    const mobileMenu = document.getElementById('mobileMenu');

    const closeSidebar = () => {
        sidebar?.classList.remove('open');
        scrim?.classList.remove('show');
    };

    menuToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('open');
        scrim?.classList.toggle('show');
    });
    scrim?.addEventListener('click', closeSidebar);

    mobileMore?.addEventListener('click', (e) => {
        e.stopPropagation();
        mobileMenu?.classList.toggle('show');
    });
    document.addEventListener('click', (e) => {
        if (mobileMenu && !mobileMenu.contains(e.target) && e.target !== mobileMore) mobileMenu.classList.remove('show');
    });

    document.querySelectorAll('.page-link').forEach(link => link.addEventListener('click', closeSidebar));

    const search = document.getElementById('pageSearch');
    search?.addEventListener('input', () => {
        const q = search.value.trim().toLocaleLowerCase('sv-SE');
        document.querySelectorAll('.page-filter-item').forEach(el => {
            const text = (el.dataset.search || '').toLocaleLowerCase('sv-SE');
            el.style.display = !q || text.includes(q) ? '' : 'none';
        });
    });

    document.getElementById('sortSelect')?.addEventListener('change', (e) => {
        const url = new URL(window.location.href);
        url.searchParams.set('sort', e.target.value);
        window.location.href = url.toString();
    });

    document.querySelectorAll('[data-modal-open]').forEach(btn => btn.addEventListener('click', () => {
        document.getElementById(btn.dataset.modalOpen)?.classList.add('open');
        mobileMenu?.classList.remove('show');
    }));
    document.querySelectorAll('[data-modal-close]').forEach(btn => btn.addEventListener('click', () => btn.closest('.modal')?.classList.remove('open')));
    document.querySelectorAll('.modal').forEach(modal => modal.addEventListener('click', e => {
        if (e.target === modal) modal.classList.remove('open');
    }));

    const editor = document.getElementById('editorText');
    const form = document.getElementById('editorForm');
    const original = form ? new FormData(form) : null;
    let submitted = false;

    const hasChanges = () => {
        if (!form || !original) return false;
        const now = new FormData(form);
        for (const [key, value] of now.entries()) {
            if (['csrf','action','old_filename'].includes(key)) continue;
            if (String(original.get(key) ?? '') !== String(value)) return true;
        }
        return false;
    };

    document.getElementById('insertTableBtn')?.addEventListener('click', () => {
        if (!editor) return;

        const start = editor.selectionStart;
        const end = editor.selectionEnd;
        const value = editor.value;

        const table =
            '| Column 1 | Column 2 | Column 3 |\n' +
            '|----------|----------|----------|\n' +
            '|          |          |          |';

        const before = value.slice(0, start);
        const after = value.slice(end);
        const leadingNewline = before !== '' && !before.endsWith('\n') ? '\n' : '';
        const trailingNewline = after !== '' && !after.startsWith('\n') ? '\n' : '';
        const inserted = leadingNewline + table + trailingNewline;

        editor.value = before + inserted + after;
        editor.focus();

        const firstCellStart = start + leadingNewline.length + 2;
        editor.setSelectionRange(firstCellStart, firstCellStart + 8);
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    });

    document.querySelectorAll('.tool').forEach(button => button.addEventListener('click', () => {
        if (!editor) return;
        const start = editor.selectionStart;
        const end = editor.selectionEnd;
        const value = editor.value;
        if (button.dataset.wrap) {
            const wrap = button.dataset.wrap;
            const selected = value.slice(start, end);
            editor.value = value.slice(0, start) + wrap + selected + wrap + value.slice(end);
            editor.focus();
            editor.setSelectionRange(start + wrap.length, end + wrap.length);
        } else if (button.dataset.prefix) {
            const prefix = button.dataset.prefix;
            const lineStart = value.lastIndexOf('\n', start - 1) + 1;
            editor.value = value.slice(0, lineStart) + prefix + value.slice(lineStart);
            editor.focus();
            editor.setSelectionRange(start + prefix.length, end + prefix.length);
        }
        editor.dispatchEvent(new Event('input'));
    }));

    form?.addEventListener('submit', () => { submitted = true; });
    document.getElementById('cancelEdit')?.addEventListener('click', e => {
        if (hasChanges() && !confirm('Du har osparade ändringar. Vill du lämna utan att spara?')) e.preventDefault();
    });
    window.addEventListener('beforeunload', e => {
        if (!submitted && hasChanges()) { e.preventDefault(); e.returnValue = ''; }
    });

    document.getElementById('copyDirectLink')?.addEventListener('click', async () => {
        const input = document.getElementById('directLink');
        if (!input) return;
        try {
            await navigator.clipboard.writeText(input.value);
        } catch {
            input.select(); document.execCommand('copy');
        }
        const btn = document.getElementById('copyDirectLink');
        const old = btn.textContent; btn.textContent = 'Kopierad'; setTimeout(() => btn.textContent = old, 1200);
    });

    const flash = document.getElementById('flashMessage');
    if (flash) setTimeout(() => flash.style.display = 'none', 2800);
})();




// Första uppstart: byt språk direkt när användaren väljer ett språk.
// Ingen inställning sparas förrän installationen skapas.
const setupLanguage = document.getElementById('setupLanguage');
if (setupLanguage) {
    setupLanguage.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('lang', setupLanguage.value);
        window.location.href = url.toString();
    });
}


