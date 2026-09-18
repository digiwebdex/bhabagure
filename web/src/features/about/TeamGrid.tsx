import Image from 'next/image';

import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { initialsOf } from '@/lib/initials';

interface TeamGridProps {
  locale: AppLocale;
  team: SiteViews['team'];
  /** Slightly wider cards on the team page, where the grid has the page to itself. */
  size?: 'section' | 'page';
}

/**
 * The CMS-managed team cards: a portrait with the name and the role reading over it. Two up on phones, three on a
 * tablet and four on a computer, whatever the size of the team.
 */
export function TeamGrid({ locale, team, size = 'section' }: TeamGridProps) {
  return (
    // The page grid grows from two on a phone to four on a wide screen; About shows its two leads side by side.
    <ul className={`grid gap-3.5 md:gap-5 ${size === 'page' ? 'grid-auto-fit-half-260' : 'grid-cols-2'}`}>
      {team.map((member) => (
        <li
          key={member.employeeCode}
          data-reveal
          className="group relative overflow-hidden rounded-20 border border-hairline bg-image-placeholder shadow-card-soft transition duration-280 ease-lift hover:-translate-y-1 hover:shadow-raised"
        >
          <div className="relative flex aspect-4/5 w-full items-center justify-center">
            {member.photo ? (
              <Image
                src={member.photo.url}
                alt={member.photo.alt || member.name}
                fill
                sizes={size === 'page' ? '(min-width: 1200px) 290px, 48vw' : '(min-width: 1200px) 270px, 48vw'}
                className="object-cover transition-transform duration-500 ease-lift group-hover:scale-105"
              />
            ) : (
              <span aria-hidden className="font-display text-40 font-extrabold text-muted-label/40">
                {initialsOf(member.name)}
              </span>
            )}

            {/* The name reads over the foot of the picture, so the card is all portrait. The staff ID stays off the
                website: it belongs on the company's own ID cards, not on a public page. */}
            <div className="absolute inset-x-0 bottom-0 bg-linear-to-t from-ink-deep/92 via-ink-deep/60 to-transparent px-3 pt-10 pb-3">
              <strong className="block text-15 leading-1.3 font-semibold text-white">{member.name}</strong>
              {/* Long titles are cut rather than allowed to climb over the face. */}
              <span className="mt-0.5 line-clamp-2 block text-12 leading-1.4 text-white/80">{member.role}</span>
              {/* On the Bangla site the English title follows, the way the team's own cards read. */}
              {locale === 'bn' && member.roleEn !== member.role ? (
                <span className="line-clamp-1 block text-11 leading-1.4 text-white/60">{member.roleEn}</span>
              ) : null}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}
