export type BreadcrumbItem = Readonly<{
  href: string;
  label: string;
}>;

const segmentLabels: Readonly<Record<string, string>> = {
  about: "О школе",
  account: "Личный кабинет",
  admin: "Админка",
  anatomiya: "Анатомия",
  articles: "Статьи",
  artrologiya: "Артрология",
  auth: "Авторизация",
  boxes: "Боксы",
  cart: "Корзина",
  exam: "Экзамен",
  faq: "FAQ",
  learning: "Обучение",
  lk: "Личный кабинет",
  osteologiya: "Остеология",
  reviews: "Отзывы",
  subjects: "Предметы",
  guides: "Мои методички",
  offers: "Предложения",
  stream: "Трансляция",
};

function fallbackLabel(parts: readonly string[], index: number): string {
  if (parts[0] === "offers" && index === 1) return "Предложение";
  if (parts[0] === "lk" && parts[1] === "guides" && index === 2) return "Методичка";
  if (parts[0] === "subjects") {
    if (index === 1) {
      return "Предмет";
    }

    if (index === 2) {
      return "Курс";
    }
  }

  if (parts[0] === "articles" && index === 1) {
    return "Статья";
  }

  return "Раздел";
}

export function resolveBreadcrumbLabel(parts: readonly string[], index: number): string {
  const segment = parts[index];
  return (segment && segmentLabels[segment]) ?? fallbackLabel(parts, index);
}

export function buildBreadcrumbs(parts: readonly string[]): BreadcrumbItem[] {
  const crumbs: BreadcrumbItem[] = [{ href: "/", label: "Главная" }];
  let currentPath = "";

  parts.forEach((part, index) => {
    currentPath += `/${part}`;
    if (currentPath === "/offers") return;
    crumbs.push({
      href: part === "auth" ? "/auth?mode=login" : currentPath,
      label: resolveBreadcrumbLabel(parts, index),
    });
  });

  return crumbs;
}
