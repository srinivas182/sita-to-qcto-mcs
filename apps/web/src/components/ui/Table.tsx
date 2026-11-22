import type { ReactNode } from 'react';
import { cx } from './cx';

export interface Column<T> {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  className?: string;
}

/** Responsive table: a table on wide screens, stacked cards on phones. */
export function Table<T>({
  rows,
  columns,
  rowKey,
  onRowClick,
  empty,
}: {
  rows: T[];
  columns: Column<T>[];
  rowKey: (row: T) => string;
  onRowClick?: (row: T) => void;
  empty?: ReactNode;
}) {
  if (rows.length === 0) return <>{empty}</>;

  return (
    <>
      <table className="hidden w-full border-collapse text-body md:table">
        <thead>
          <tr>
            {columns.map((c) => (
              <th
                key={c.key}
                className={cx(
                  'border-b border-line px-3 pb-2.5 text-left text-caption font-semibold uppercase tracking-wider text-muted',
                  c.className,
                )}
              >
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={rowKey(row)}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              className={cx('group', onRowClick && 'cursor-pointer')}
            >
              {columns.map((c) => (
                <td
                  key={c.key}
                  className={cx(
                    'border-b border-line-soft px-3 py-3 align-middle group-last:border-0 group-hover:bg-surface-muted',
                    c.className,
                  )}
                >
                  {c.cell(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      <ul className="grid gap-2 md:hidden">
        {rows.map((row) => (
          <li
            key={rowKey(row)}
            onClick={onRowClick ? () => onRowClick(row) : undefined}
            className="rounded-lg border border-line-soft p-3"
          >
            {columns.map((c) => (
              <div key={c.key} className="flex items-start justify-between gap-3 py-0.5">
                <span className="text-caption uppercase tracking-wider text-faint">{c.header}</span>
                <span className="text-right">{c.cell(row)}</span>
              </div>
            ))}
          </li>
        ))}
      </ul>
    </>
  );
}
