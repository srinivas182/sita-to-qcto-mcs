import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cx } from './cx';
import { Spinner } from './Spinner';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
type Size = 'sm' | 'md' | 'lg';

const variants: Record<Variant, string> = {
  primary: 'bg-primary-700 text-white hover:bg-primary-800 shadow-sm shadow-primary-900/10 disabled:bg-primary-300',
  secondary: 'bg-surface text-ink border border-line hover:border-primary-200 hover:bg-primary-50/40',
  ghost: 'text-muted hover:text-ink hover:bg-line-soft',
  danger: 'bg-danger text-white hover:brightness-95',
};

const sizes: Record<Size, string> = {
  sm: 'h-8 px-3 text-small rounded-md gap-1.5',
  md: 'h-10 px-4 text-body rounded-lg gap-2',
  lg: 'h-11 px-5 text-[14px] rounded-lg gap-2',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
  block?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading, icon, block, className, children, disabled, ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      className={cx(
        'inline-flex items-center justify-center font-medium transition-[background,box-shadow,transform] duration-150 active:scale-[.98] disabled:cursor-not-allowed disabled:opacity-60',
        variants[variant],
        sizes[size],
        block && 'w-full',
        className,
      )}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading ? <Spinner className="size-4" /> : icon}
      {children}
    </button>
  );
});
