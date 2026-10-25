/*
 * Sortable Tables — Auto-attaches click-to-sort to every <th> in admin/presales tables.
 * The initial server-side row order is preserved as the default until a header is clicked.
 *
 * Opt-out per table: add class "no-sort" or attribute data-no-sort to <table>.
 * Opt-out per column: add class "no-sort" or attribute data-no-sort to <th>.
 * Override detected sort type per column: data-sort-type="number|date|string" on <th>.
 * Override per-cell sort key: data-sort="..." on <td>.
 */
(function () {
    'use strict';

    const DATE_RE = /^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/;
    const NON_SORT_HEADERS = ['aktionen', 'aktion'];

    function getCellText(cell) {
        if (!cell) return '';
        if (cell.dataset && cell.dataset.sort !== undefined) return cell.dataset.sort;
        return (cell.innerText || cell.textContent || '').trim();
    }

    function detectType(values) {
        let allDate = true;
        let allNumeric = true;
        let nonEmptyCount = 0;
        for (const raw of values) {
            const v = raw.trim();
            if (v === '' || v === '-') continue;
            nonEmptyCount++;
            if (!DATE_RE.test(v)) allDate = false;
            const numCandidate = v.replace(/[€%\s]/g, '').replace(/\./g, '').replace(',', '.');
            if (!/^-?\d+(\.\d+)?$/.test(numCandidate)) allNumeric = false;
        }
        if (nonEmptyCount === 0) return 'string';
        if (allDate) return 'date';
        if (allNumeric) return 'number';
        return 'string';
    }

    function parseValue(v, type) {
        const trimmed = (v || '').trim();
        if (type === 'date') {
            const m = trimmed.match(DATE_RE);
            if (m) {
                return new Date(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)).getTime();
            }
            return Number.NEGATIVE_INFINITY;
        }
        if (type === 'number') {
            if (trimmed === '' || trimmed === '-') return Number.NEGATIVE_INFINITY;
            const cleaned = trimmed.replace(/[€%\s]/g, '').replace(/\./g, '').replace(',', '.');
            const n = parseFloat(cleaned);
            return isNaN(n) ? Number.NEGATIVE_INFINITY : n;
        }
        return trimmed.toLowerCase();
    }

    function sortTable(table, headerRow, colIdx, dir) {
        const tbody = table.tBodies[0];
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll(':scope > tr'));
        if (rows.length < 2) return;
        // Skip "no data" placeholder row (single tr with colspan)
        if (rows.length === 1 && rows[0].children.length === 1 && rows[0].children[0].hasAttribute('colspan')) return;

        const headerCell = headerRow.children[colIdx];
        const overrideType = headerCell && headerCell.dataset ? headerCell.dataset.sortType : '';
        const values = rows.map(r => getCellText(r.children[colIdx]));
        const type = overrideType || detectType(values);
        const parsed = values.map(v => parseValue(v, type));

        const indexed = rows.map((row, i) => ({ row, key: parsed[i], orig: i }));
        indexed.sort((a, b) => {
            if (a.key < b.key) return dir === 'asc' ? -1 : 1;
            if (a.key > b.key) return dir === 'asc' ? 1 : -1;
            return a.orig - b.orig;
        });

        const frag = document.createDocumentFragment();
        indexed.forEach(item => frag.appendChild(item.row));
        tbody.appendChild(frag);
    }

    function isSkippableHeader(th) {
        if (!th) return true;
        if (th.classList && th.classList.contains('no-sort')) return true;
        if (th.dataset && th.dataset.noSort !== undefined) return true;
        const text = (th.textContent || '').trim().toLowerCase();
        if (text === '') return true;
        if (NON_SORT_HEADERS.includes(text)) return true;
        return false;
    }

    function makeSortable(table) {
        if (!table.tHead || !table.tBodies[0]) return;
        if (table.dataset.sortableInit) return;
        if (table.classList.contains('no-sort') || (table.dataset && table.dataset.noSort !== undefined)) return;
        // Skip tables inside modals — they're often dynamically rebuilt helper tables
        if (table.closest('.modal')) return;

        table.dataset.sortableInit = '1';

        const headerRow = table.tHead.rows[table.tHead.rows.length - 1];
        if (!headerRow) return;

        Array.from(headerRow.children).forEach((th, idx) => {
            if (isSkippableHeader(th)) return;

            th.classList.add('sortable-th');
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.setAttribute('role', 'button');
            th.setAttribute('aria-sort', 'none');

            const arrow = document.createElement('span');
            arrow.className = 'sort-arrow';
            arrow.style.marginLeft = '6px';
            arrow.style.opacity = '0.35';
            arrow.style.fontSize = '0.85em';
            arrow.textContent = '⇵'; // ⇅
            th.appendChild(arrow);

            th.addEventListener('click', function () {
                const currentDir = th.dataset.sortDir;
                const dir = currentDir === 'asc' ? 'desc' : 'asc';

                Array.from(headerRow.children).forEach((other) => {
                    if (other !== th) {
                        delete other.dataset.sortDir;
                        other.setAttribute('aria-sort', 'none');
                        const a = other.querySelector('.sort-arrow');
                        if (a) {
                            a.textContent = '⇵';
                            a.style.opacity = '0.35';
                        }
                    }
                });

                th.dataset.sortDir = dir;
                th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
                arrow.textContent = dir === 'asc' ? '▲' : '▼';
                arrow.style.opacity = '1';

                sortTable(table, headerRow, idx, dir);
            });
        });
    }

    function init() {
        document.querySelectorAll('table.table').forEach(makeSortable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
