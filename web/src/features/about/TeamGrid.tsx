import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { initialsOf } from '@/lib/initials';

interface TeamGridProps {
  locale: AppLocale;
  team: SiteViews['team'];
  /** Wider cards on the team page, where the grid has the page to itself. */
  size?: 'section' | 'page';
}

/** The CMS-managed team cards: photo (or initials), name, role and staff ID. Shared by About and the team page. */
export async function TeamGrid({ locale, team, size = 'section' }: TeamGridProps) {
  const t = await getTranslations({ locale });

  return (
    // The page keeps card widths with a small team (auto-fill), two up on phones; About fills its column as before.
    // (Two 48 % columns leave 4 % for the gap: 14px fits a 360px phone.)
    <ul className={`grid ${size === 'page' ? 'grid-auto-fill-half-220 gap-3.5 md:gap-6' : 'grid-auto-fit-190 gap-4'}`}>
      {team.map((member) => (
        <li key={member.employeeCode} data-reveal className="flex flex-col overflow-hidden rounded-20 border border-hairline bg-white">
          <div className="relative flex aspect-square w-full items-center justify-center bg-image-placeholder">
            {member.photo ? (
              <Image
                src={member.photo.url}
                alt={member.photo.alt || member.name}
                fill
                sizes={size === 'page' ? '(min-width: 1200px) 290px, 50vw' : '(min-width: 1200px) 270px, 50vw'}
                className="object-cover"
              />
            ) : (
              <span aria-hidden className="font-display text-40 font-extrabold text-muted-label/40">
                {initialsOf(member.name)}
              </span>
            )}
          </div>
          <div className="flex flex-col gap-1 p-4">
            <strong className="text-16 leading-1.3 font-semibold">{member.name}</strong>
            <span className="text-13 text-muted">{locale === 'bn' ? `${member.role} · ${member.roleEn}` : member.role}</span>
            <span className="mt-0.5 font-display text-12 font-bold tracking-caps-print text-blue">
              {t('about.idLabel')} {member.employeeCode}
            </span>
          </div>
        </li>
      ))}
    </ul>
  );
}
