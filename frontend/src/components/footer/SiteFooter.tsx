import Link from "next/link";
import Image from "next/image";
import { ContactChannelIcon } from "@/components/button/ContactChannelButton";
import SupportTicketModal from "@/components/support/SupportTicketModal";
import LegalDocumentLink from "@/components/documents/LegalDocumentLink";
import CooperationModal from "@/components/footer/CooperationModal";
import { SCHOOL_TELEGRAM_URL, SCHOOL_YOUTUBE_URL, SCHOOL_VK_URL } from "@/lib/contactLinks";

const footerNavLinks = [
  { href: "/subjects", label: "Предметы" },
  { href: "/boxes", label: "Боксы" },
  { href: "/about", label: "О школе" },
  { href: "/articles", label: "Статьи" },
  { href: "/reviews", label: "Отзывы" },
  { href: "/faq", label: "Вопросы" },
] as const;

// MAX retains its existing destination until the owner confirms its URL.
const socialLinks = [
  { label: "Telegram", href: SCHOOL_TELEGRAM_URL, icon: "telegram" },
  { label: "MAX", href: "/#contacts", icon: "max" },
  { label: "YouTube", href: SCHOOL_YOUTUBE_URL, icon: "youtube" },
  { label: "ВКонтакте", href: SCHOOL_VK_URL, icon: "vk" },
] as const;

const legalLinks = [
  { documentId: "offer", label: "Оферта" },
  { documentId: "privacy", label: "Персональные данные" },
  { documentId: "license", label: "Лицензия" },
] as const;

const linkClassName = "ui-text-link ui-text-link--muted ui-text-link--interactive min-h-5 text-sm";
const headingClassName = "ui-text-secondary min-h-6 text-sm font-semibold leading-6";

function FooterSocialIcon({ channel }: { readonly channel: typeof socialLinks[number]["icon"] }) {
  if (channel === "telegram" || channel === "max") {
    return <ContactChannelIcon channel={channel} monochrome />;
  }
  if (channel === "vk") {
    return (
      <svg className="size-5" viewBox="0 0 101 100" fill="currentColor" aria-hidden="true" focusable="false">
        <path fillRule="evenodd" clipRule="evenodd" d="M7.52944 7.02944C0.5 14.0589 0.5 25.3726 0.5 48V52C0.5 74.6274 0.5 85.9411 7.52944 92.9706C14.5589 100 25.8726 100 48.5 100H52.5C75.1274 100 86.4411 100 93.4706 92.9706C100.5 85.9411 100.5 74.6274 100.5 52V48C100.5 25.3726 100.5 14.0589 93.4706 7.02944C86.4411 0 75.1274 0 52.5 0H48.5C25.8726 0 14.5589 0 7.52944 7.02944ZM17.3752 30.4169C17.9168 56.4169 30.9167 72.0418 53.7084 72.0418H55.0003V57.1668C63.3753 58.0001 69.7082 64.1252 72.2498 72.0418H84.0835C80.8335 60.2085 72.2914 53.6668 66.9581 51.1668C72.2914 48.0835 79.7915 40.5835 81.5831 30.4169H70.8328C68.4995 38.6669 61.5836 46.1668 55.0003 46.8751V30.4169H44.2499V59.2501C37.5833 57.5835 29.1668 49.5002 28.7918 30.4169H17.3752Z" />
      </svg>
    );
  }
  return (
    <svg className="h-5 w-auto max-w-none shrink-0" viewBox="0 0 822 576" fill="currentColor" aria-hidden="true" focusable="false">
      <g transform="matrix(1,0,0,1,-1197.194167,-192.539583)">
        <g transform="matrix(4.166667,0,0,4.166667,1526.019167,356.952083)">
          <path fillRule="nonzero" d="M0,59.189L0,0L51.125,29.523L0,59.189ZM114.059,-17.857C111.754,-26.354 105.273,-32.979 96.633,-35.283C81.367,-39.459 19.586,-39.459 19.586,-39.459C19.586,-39.459 -42.051,-39.459 -57.316,-35.283C-65.813,-32.979 -72.438,-26.354 -74.886,-17.857C-78.918,-2.592 -78.918,29.523 -78.918,29.523C-78.918,29.523 -78.918,61.637 -74.886,77.047C-72.438,85.399 -65.813,92.168 -57.316,94.472C-42.051,98.648 19.586,98.648 19.586,98.648C19.586,98.648 81.367,98.648 96.633,94.472C105.273,92.168 111.754,85.399 114.059,77.047C118.234,61.637 118.234,29.523 118.234,29.523C118.234,29.523 118.234,-2.592 114.059,-17.857" />
        </g>
      </g>
    </svg>
  );
}

export default function SiteFooter() {
  return (
    <footer className="site-footer px-5 md:px-0" data-ui="site.footer">
      <div className="site-footer__panel mx-auto w-full max-w-6xl overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-learning-card-surface)]" data-ui="site.footer.panel">
        <div className="site-footer__columns grid items-start gap-x-8 gap-y-6 p-6 md:p-8">
          <div className="site-footer__brand min-w-0">
            <Link href="/" className="inline-flex min-h-6 items-center gap-2 text-base font-bold tracking-tight uppercase" style={{ color: "var(--color-header-island-text)" }} data-ui="site.footer.logo">
              <Image src="/images/brand/christmedschool-graduation-cap.svg" width={256} height={170} className="h-auto w-8 shrink-0" alt="" aria-hidden="true" />
              christmedschool
            </Link>
            <p className="ui-text-secondary mt-2 max-w-xs text-sm leading-relaxed">
              Онлайн-школа для абитуриентов и студентов медицинских вузов.
            </p>
            <nav aria-label="Социальные сети" className="mt-3 flex items-center gap-3" data-ui="site.footer.social">
              {socialLinks.map((item) => (
                <Link key={item.label} href={item.href} aria-label={item.label} title={item.label} className="ui-text-link ui-text-link--muted ui-text-link--interactive size-7 justify-center">
                  <span className="inline-flex h-5 min-w-5 shrink-0 items-center justify-center"><FooterSocialIcon channel={item.icon} /></span>
                </Link>
              ))}
            </nav>
          </div>

          <nav aria-labelledby="footer-menu-title" className="site-footer__navigation min-w-0" data-ui="site.footer.nav">
            <p id="footer-menu-title" className={headingClassName}>Меню</p>
            <ul className="mt-2 space-y-1">
              {footerNavLinks.map((item) => (
                <li key={item.href} className="flex"><Link href={item.href} className={linkClassName}>{item.label}</Link></li>
              ))}
            </ul>
          </nav>

          <nav aria-labelledby="footer-documents-title" className="site-footer__documents min-w-0" data-ui="site.footer.legalLinks">
            <p id="footer-documents-title" className={headingClassName}>Документы</p>
            <ul className="mt-2 space-y-1">
              {legalLinks.map((item) => (
                <li key={item.documentId} className="flex"><LegalDocumentLink documentId={item.documentId} className={linkClassName} dataUi={`site.footer.document.${item.documentId}`}>{item.label}</LegalDocumentLink></li>
              ))}
            </ul>
          </nav>

          <div className="site-footer__contact min-w-0" data-ui="site.footer.contact">
            <p className={headingClassName}>Помощь</p>
            <ul className="mt-2 space-y-1 text-sm leading-none">
              <li className="flex"><SupportTicketModal triggerDataUi="site.footer.supportButton" triggerVariant="text" /></li>
              <li className="flex"><Link href="/account" className={linkClassName} data-ui="site.footer.account">Личный кабинет</Link></li>
              <li className="flex"><CooperationModal /></li>
            </ul>
          </div>
        </div>

        <div className="site-footer__legal px-6 py-3 md:px-8" data-ui="site.footer.legal">
          <div className="ui-text-secondary flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs">
            <p className="whitespace-nowrap">© {new Date().getFullYear()} Все права защищены</p>
            <a href="https://t.me/a1_dev" target="_blank" rel="noopener noreferrer" className="ui-text-link ui-text-link--muted ui-text-link--interactive min-h-6" style={{ font: "inherit" }} aria-label="Разработано a1_dev в Telegram" data-ui="site.footer.developer">
              Разработано
              <span className="inline-flex items-center gap-1">
                <span className="inline-flex size-3 shrink-0 items-center justify-center [&>svg]:size-3!">
                  <ContactChannelIcon channel="telegram" monochrome />
                </span>
                a1_dev
              </span>
            </a>
          </div>
        </div>
      </div>
    </footer>
  );
}
