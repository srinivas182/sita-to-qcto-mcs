import * as D from '@radix-ui/react-dialog';
import { useNavigate } from '@tanstack/react-router';
import { CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { cx } from '@/components/ui';
import { useT } from '@/lib/i18n';
import type { NavItem } from './nav';

/** ⌘K / Ctrl+K: jump anywhere by typing. Arrow keys + Enter, no mouse needed. */
export function CommandPalette({
  items,
  open,
  onOpenChange,
}: {
  items: NavItem[];
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useT();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        onOpenChange(!open);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onOpenChange]);

  const results = useMemo(() => {
    const q = query.trim().toLowerCase();
    return items.filter((i) => !q || t(i.label).toLowerCase().includes(q));
  }, [items, query, t]);

  const go = (item: NavItem | undefined) => {
    if (!item) return;
    onOpenChange(false);
    setQuery('');
    void navigate({ to: item.to });
  };

  return (
    <D.Root open={open} onOpenChange={onOpenChange}>
      <D.Portal>
        <D.Overlay className="fixed inset-0 z-40 bg-primary-950/25 backdrop-blur-[2px]" />
        <D.Content className="card fixed top-[12vh] left-1/2 z-50 w-[min(560px,94vw)] -translate-x-1/2 animate-in overflow-hidden p-0 shadow-2xl">
          <D.Title className="sr-only">{t('shell.command')}</D.Title>
          <div className="flex items-center gap-3 border-b border-line px-4">
            <Search className="size-4 text-faint" />
            <input
              autoFocus
              value={query}
              onChange={(e) => {
                setQuery(e.target.value);
                setActive(0);
              }}
              onKeyDown={(e) => {
                if (e.key === 'ArrowDown') setActive((a) => Math.min(a + 1, results.length - 1));
                if (e.key === 'ArrowUp') setActive((a) => Math.max(a - 1, 0));
                if (e.key === 'Enter') go(results[active]);
              }}
              placeholder={t('shell.command')}
              className="h-12 flex-1 bg-transparent text-[15px] outline-none placeholder:text-faint"
              role="combobox"
              aria-expanded
              aria-controls="command-results"
            />
          </div>
          <ul id="command-results" role="listbox" className="max-h-80 overflow-y-auto p-2">
            {results.map((item, i) => (
              <li key={item.to} role="option" aria-selected={i === active}>
                <button
                  onMouseEnter={() => setActive(i)}
                  onClick={() => go(item)}
                  className={cx(
                    'flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-body',
                    i === active ? 'bg-primary-50 text-primary-800' : 'text-ink',
                  )}
                >
                  <item.icon className="size-4" />
                  <span className="flex-1">{t(item.label)}</span>
                  {i === active ? <CornerDownLeft className="size-3.5 text-faint" /> : null}
                </button>
              </li>
            ))}
            {results.length === 0 ? (
              <li className="px-3 py-6 text-center text-small text-muted">{t('common.none')}</li>
            ) : null}
          </ul>
        </D.Content>
      </D.Portal>
    </D.Root>
  );
}
