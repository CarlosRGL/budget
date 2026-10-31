import { Fragment } from 'react';
import type { ReactNode } from 'react';

/**
 * Puts React nodes (a link, a name in bold) in a translated sentence, so the
 * sentence stays one key whatever the word order of the language:
 *
 *     <Trans
 *         text={t('Do you really want to leave :name?')}
 *         values={{ name: <strong>{team.name}</strong> }}
 *     />
 */
export function Trans({
    text,
    values,
}: {
    text: string;
    values: Record<string, ReactNode>;
}) {
    const names = Object.keys(values).sort((a, b) => b.length - a.length);

    if (names.length === 0) {
        return <>{text}</>;
    }

    // With a capturing group, split() puts each placeholder name at the odd
    // indexes, between the pieces of text.
    const parts = text.split(new RegExp(`:(${names.join('|')})`, 'g'));

    return (
        <>
            {parts.map((part, index) => (
                <Fragment key={index}>
                    {index % 2 === 1 ? values[part] : part}
                </Fragment>
            ))}
        </>
    );
}
