# Schoolplein

Een digitaal dorpsplein rond school. Eerste echte codebasis voor Dalton Pieterskerkhof.

## Stack
Next.js + TypeScript + Supabase.

## Lokaal starten
1. Installeer Node.js 20+.
2. `npm install`
3. Kopieer `.env.example` naar `.env.local` en vul Supabase URL + anon key in.
4. `npm run dev`
5. Open http://localhost:3000

## Supabase
Maak een Supabase-project en voer `supabase/schema.sql` uit in de SQL editor. Daarna kunnen we auth, echte activiteiten en activiteitengesprekken aansluiten.

## Productregels die in de volgende stap leidend zijn
- Geen WhatsApp-groepen voor activiteiten.
- Geen likes, volgers of populariteitsscores.
- Geen privé-DM's in de MVP.
- Communicatie hoort bij de activiteit.
- Kinderen worden door ouder/verzorger beheerd.
- Deelnemersinformatie wordt bewust en terughoudend zichtbaar gemaakt.
- Community/alumni/extra categorieën zijn oranje.
- Maandelijkse activiteitlimiet wordt configureerbaar gemaakt en eerst getest in de pilot.


## Productregel: één open plein
Schoolplein gebruikt bewust geen activiteiten per schoolgroep. Elke activiteit is standaard toegankelijk voor de hele community. Groepsnamen zoals Grachtenvaarders en Dombeklimmers kunnen in schoolcontext zichtbaar zijn, maar bepalen niet de toegang.

### Oud-leerlingen
We gebruiken in de interface de term **oud-leerlingen**, niet "alumni". Oud-leerlingen die nog kind zijn kunnen een eigen account hebben en eenvoudig meedoen aan activiteiten. De bovengrens is configureerbaar via `community_settings.former_student_max_age` en staat in de demo op 18 jaar.
