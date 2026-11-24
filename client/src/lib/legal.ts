// Legal documents of the public demo (TASK-016). Drafts: every fact the agent could not establish is a visible
// placeholder `[do uzupełnienia: …]`, and the service provider reviews the text before it is final. The content states
// only what the application does (retention commands, providers in the deployment configuration).

export interface LegalSection {
  heading: string
  paragraphs?: string[]
  items?: string[]
}

export interface LegalDocument {
  path: string
  title: string
  version: string
  sections: LegalSection[]
}

const PROVIDER = '[do uzupełnienia: nazwa usługodawcy, adres, NIP]'
const CONTACT = '[do uzupełnienia: adres e-mail do kontaktu w sprawach danych osobowych]'

export const PRIVACY: LegalDocument = {
  path: '/polityka-prywatnosci',
  title: 'Polityka prywatności',
  version: '24 listopada 2026 (wersja robocza)',
  sections: [
    {
      heading: 'Kto przetwarza dane',
      paragraphs: [
        `Administratorem danych osobowych przetwarzanych w wersji demonstracyjnej Tabularius jest ${PROVIDER}. Kontakt: ${CONTACT}.`,
        'Tabularius w tej wersji działa wyłącznie na fikcyjnych (syntetycznych) danych firmowych. Dane osobowe pojawiają się tylko wtedy, gdy ktoś wyśle wiadomość na adres demo albo wpisze je w panelu.',
      ],
    },
    {
      heading: 'Jakie dane przetwarzamy i skąd je mamy',
      items: [
        'Wiadomości wysłane na adres demo: adres nadawcy, temat, treść i nagłówki wiadomości. Załączników nie zapisujemy ani nie otwieramy.',
        'Wiadomości wysyłane przez Tabularius w odpowiedzi: adres odbiorcy (nadawcy prośby) i treść wiadomości z szablonu.',
        'Adresy e-mail wpisane w panelu administratora przy tworzeniu kont fikcyjnych pracowników. Wiadomości z hasłami do takich kont nie są wysyłane.',
        'Ciasteczko sesji tabularius_session po zalogowaniu się na konto demo.',
        'Dane techniczne: adres IP i czas żądania, używane do limitów liczby żądań i w logach serwera, oraz dziennik zdarzeń bezpieczeństwa z identyfikatorami kont i zgłoszeń (bez adresów e-mail i treści).',
      ],
    },
    {
      heading: 'W jakim celu i na jakiej podstawie',
      items: [
        'Obsługa prośby o raport: odczytanie wiadomości, przygotowanie raportu z danych syntetycznych i wysłanie odpowiedzi na adres nadawcy (art. 6 ust. 1 lit. b RODO — świadczenie usługi demonstracyjnej na zasadach regulaminu).',
        'Bezpieczeństwo i ochrona przed nadużyciami: limity wiadomości na nadawcę, wykrywanie prób nadużyć, dziennik zdarzeń (art. 6 ust. 1 lit. f RODO — prawnie uzasadniony interes usługodawcy).',
        'Wykrywanie i naprawa błędów aplikacji (art. 6 ust. 1 lit. f RODO).',
      ],
    },
    {
      heading: 'Komu przekazujemy dane',
      items: [
        'Dostawca serwera, na którym działa aplikacja i skrzynka pocztowa: [do uzupełnienia: nazwa dostawcy serwera i skrzynki pocztowej].',
        'Brevo (Sendinblue SAS, Francja) — wysyłka wiadomości e-mail: adres odbiorcy i treść wiadomości z szablonu.',
        'OpenAI (OpenAI, L.L.C., USA) — rozpoznanie, o jaki raport chodzi w prośbie: temat i treść wiadomości w całości, razem z ewentualnym podpisem; adresu nadawcy z nagłówka wiadomości nie przekazujemy. Wysyłamy żądania z wyłączonym zapisywaniem odpowiedzi (store: false); według zasad dostawcy dane z API mogą być przez niego przechowywane do 30 dni w celu wykrywania nadużyć.',
        'Sentry (Functional Software, Inc., USA) — raporty o błędach aplikacji, po włączeniu tej usługi. Przed wysłaniem usuwamy z nich adresy e-mail, treści wiadomości, hasła, tokeny i dane logowania.',
      ],
      paragraphs: [
        'Przekazanie danych do USA (OpenAI, Sentry) odbywa się na podstawie [do uzupełnienia: podstawa przekazania, np. EU-U.S. Data Privacy Framework albo standardowe klauzule umowne].',
      ],
    },
    {
      heading: 'Jak długo przechowujemy dane',
      items: [
        'Adres nadawcy, temat, nagłówki i treść otrzymanej wiadomości: 7 dni, potem są usuwane automatycznie.',
        'Adres odbiorcy i treść wysłanej odpowiedzi: 7 dni, potem są usuwane automatycznie.',
        'Pliki raportów (dane syntetyczne): 7 dni.',
        'Dziennik zdarzeń bezpieczeństwa (identyfikatory kont i zgłoszeń, bez adresów i treści): 90 dni.',
        'Po usunięciu treści zostają dane zgłoszenia bez treści: numer, status, rozpoznany raport, parametry, alias uczestnika i jednokierunkowy skrót adresu nadawcy (służy do limitów wiadomości; bez klucza serwera nie da się z niego odtworzyć adresu).',
        'Konta fikcyjne utworzone w panelu: do ich usunięcia albo do odtworzenia danych demonstracyjnych.',
        'Logi serwera aplikacji: do zastąpienia nowszymi wpisami (ograniczony rozmiar). Logi nie zawierają treści wiadomości.',
        'Kopie zapasowe serwera: [do uzupełnienia: okres przechowywania kopii u dostawcy serwera].',
      ],
    },
    {
      heading: 'Twoje prawa',
      paragraphs: [
        `Masz prawo dostępu do swoich danych, ich sprostowania, usunięcia, ograniczenia przetwarzania, przenoszenia oraz wniesienia sprzeciwu wobec przetwarzania opartego na prawnie uzasadnionym interesie. Napisz na ${CONTACT}.`,
        'Masz prawo wnieść skargę do Prezesa Urzędu Ochrony Danych Osobowych (ul. Stawki 2, 00-193 Warszawa).',
        'Podanie danych jest dobrowolne. Bez adresu nadawcy nie możemy odpowiedzieć na prośbę.',
      ],
    },
    {
      heading: 'Ciasteczka',
      paragraphs: [
        'Używamy tylko ciasteczka sesji, niezbędnego do działania logowania. Wygasa po zamknięciu przeglądarki. Nie używamy ciasteczek analitycznych ani reklamowych.',
      ],
    },
    {
      heading: 'Zautomatyzowane przetwarzanie',
      paragraphs: [
        'Treść prośby jest automatycznie analizowana, żeby rozpoznać, jakiego raportu dotyczy. Na tej podstawie nie są podejmowane decyzje wywołujące wobec Ciebie skutki prawne; w najgorszym razie raport nie powstaje albo prośba trafia do administratora.',
      ],
    },
  ],
}

export const TERMS: LegalDocument = {
  path: '/regulamin',
  title: 'Regulamin wersji demonstracyjnej',
  version: '24 listopada 2026 (wersja robocza)',
  sections: [
    {
      heading: 'Usługodawca i usługa',
      paragraphs: [
        `Usługę świadczy ${PROVIDER}. Kontakt: [do uzupełnienia: adres e-mail do kontaktu i reklamacji].`,
        'Tabularius w wersji demonstracyjnej pokazuje, jak prośba wysłana e-mailem zamienia się w raport CSV. Raporty powstają wyłącznie z fikcyjnych (syntetycznych) danych firmy przykładowej. Usługa jest bezpłatna.',
      ],
    },
    {
      heading: 'Jak korzystać',
      items: [
        'Wyślij prośbę o raport na adres demo podany na stronie logowania. Odpowiedź otrzymasz na adres, z którego wysłano wiadomość.',
        'Dane logowania do kont demonstracyjnych są wspólne i widoczne dla wszystkich uczestników. Widoki zgłoszeń, raportów i plików w panelu są wspólne dla uczestników.',
        'Panel nie pokazuje adresów e-mail, tematów ani treści wiadomości uczestników — tylko alias uczestnika, rozpoznany raport, parametry i status.',
        'Konta fikcyjnych pracowników utworzone w panelu administratora, razem z wpisanym adresem e-mail, widzą wszyscy uczestnicy. Nie wpisuj tam prawdziwych adresów.',
      ],
    },
    {
      heading: 'Czego nie wolno',
      items: [
        'Wysyłać danych poufnych, danych osobowych innych osób ani prawdziwych danych firmowych.',
        'Próbować uzyskać dostęp do danych lub funkcji spoza wersji demonstracyjnej, obchodzić zabezpieczenia ani zakłócać działania usługi.',
        'Wysyłać wiadomości w sposób zautomatyzowany lub masowy, w tym w imieniu innych osób.',
        'Zmieniać lub blokować wspólnych kont demonstracyjnych.',
      ],
      paragraphs: [
        'Prośby wykraczające poza katalog raportów albo zawierające polecenia innego rodzaju (np. usunięcia danych) są odrzucane.',
      ],
    },
    {
      heading: 'Limity i dostępność',
      items: [
        'Jeden nadawca może wysłać do 5 próśb na godzinę i 20 na dobę; łączny limit usługi to 200 próśb na dobę.',
        'Pliki raportów są dostępne przez 7 dni.',
        'Usługa jest wersją demonstracyjną: działa bez gwarancji dostępności i może być zmieniana, wstrzymywana lub wyłączona, a dane demonstracyjne mogą być odtwarzane od nowa.',
      ],
    },
    {
      heading: 'Odpowiedzialność',
      paragraphs: [
        'Raporty zawierają dane fikcyjne i nie mogą być podstawą decyzji biznesowych. [do uzupełnienia: zakres odpowiedzialności usługodawcy, zgodny z przepisami wobec konsumentów].',
      ],
    },
    {
      heading: 'Reklamacje',
      paragraphs: [
        'Reklamacje dotyczące działania usługi można zgłaszać na [do uzupełnienia: adres e-mail do kontaktu i reklamacji]. Odpowiemy w ciągu 14 dni.',
      ],
    },
    {
      heading: 'Dane osobowe',
      paragraphs: ['Zasady przetwarzania danych osobowych opisuje polityka prywatności.'],
    },
    {
      heading: 'Zmiany regulaminu',
      paragraphs: [
        'O zmianach informujemy na tej stronie z datą nowej wersji. [do uzupełnienia: prawo właściwe i sąd, jeśli usługodawca chce je wskazać].',
      ],
    },
  ],
}

export const LEGAL_DOCUMENTS = [TERMS, PRIVACY]

/** Splits a text into plain parts and placeholders, so placeholders can be highlighted. */
export function placeholderParts(text: string): Array<{ text: string; placeholder: boolean }> {
  return text
    .split(/(\[do uzupełnienia:[^\]]*\])/)
    .filter((part) => part !== '')
    .map((part) => ({ text: part, placeholder: part.startsWith('[do uzupełnienia:') }))
}
