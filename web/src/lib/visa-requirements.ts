export interface RequirementGroup {
  heading: string | null;
  items: string[];
}

/**
 * A visa's requirements in groups: a line ending in a colon — "For business person:" — heads the lines under it, and any
 * lines before the first heading form a group with none. A heading with nothing under it is dropped. Mirrors
 * VisaService::groups() in the API, which makes the requirements PDF from the same lines.
 */
export function requirementGroups(lines: string[]): RequirementGroup[] {
  const groups: RequirementGroup[] = [{ heading: null, items: [] }];
  for (const line of lines) {
    const heading = /^(.*\S)\s*[:：]$/u.exec(line);
    if (heading) groups.push({ heading: heading[1], items: [] });
    else groups[groups.length - 1].items.push(line);
  }
  return groups.filter((group) => group.items.length > 0);
}
