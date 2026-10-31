import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * The app icon: a coin going into an envelope (public/favicon.svg, also used
 * for the home screen icons).
 */
export default function AppLogoIcon({
    className,
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/favicon.svg"
            alt=""
            {...props}
            className={cn('aspect-square rounded-[22%]', className)}
        />
    );
}
