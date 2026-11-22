<?php
declare(strict_types=1);
/**
 * GET /v1/afkortingen: abbreviations and terms that appear in P2000 alerts, explained in A-Alerts' own words (nl + en).
 * hoofdletters=true: match only the exact capitals as a whole word (so "TS" never matches "ts" inside a word);
 * false: a phrase, matched case-insensitively. dienst: the service the term belongs to, or null for all.
 * One list for the apps, the website and the alert pages; changes here reach all of them.
 */

const AA_AFKORTINGEN_VERSIE = '2026-11-22';

function aaAfkortingen(): array {
  $l = [
    // Priority / urgency
    ['A0', 'Ambulance met de hoogste urgentie: direct levensgevaar, bijvoorbeeld een reanimatie.', 'Ambulance at the highest urgency: immediate danger to life, for example a resuscitation.', 'ambulance', true],
    ['A1', 'Ambulance met spoed: mogelijk levensbedreigend, met zwaailicht en sirene. Streefnorm: binnen 15 minuten ter plaatse.', 'Ambulance with lights and sirens: possibly life-threatening. Target: on scene within 15 minutes.', 'ambulance', true],
    ['A2', 'Ambulance met gepaste spoed: niet direct levensbedreigend, maar wel zo snel mogelijk. Meestal zonder sirene.', 'Ambulance with due urgency: not immediately life-threatening, but as soon as possible. Usually without sirens.', 'ambulance', true],
    ['B1', 'Besteld ambulancevervoer met een vaste tijd, zonder spoed (bijvoorbeeld van of naar het ziekenhuis).', 'Scheduled ambulance transport at a set time, without urgency (for example to or from hospital).', 'ambulance', true],
    ['B2', 'Besteld ambulancevervoer zonder spoed, tijdstip in overleg.', 'Scheduled ambulance transport without urgency, time by arrangement.', 'ambulance', true],
    ['B3', 'Besteld ambulancevervoer zonder spoed en zonder vaste tijd.', 'Scheduled ambulance transport without urgency or a set time.', 'ambulance', true],
    ['P1', 'Prioriteit 1: met spoed, zwaailicht en sirene.', 'Priority 1: urgent, with lights and sirens.', null, true],
    ['P2', 'Prioriteit 2: snel ter plaatse, maar zonder zwaailicht en sirene.', 'Priority 2: on scene quickly, but without lights and sirens.', null, true],
    ['P3', 'Prioriteit 3: geen spoed.', 'Priority 3: no urgency.', null, true],
    ['Prio', 'Prioriteit van de inzet (1 is het hoogst).', 'Priority of the call (1 is highest).', null, true],
    // Ambulance
    ['Ambu', 'Ambulance. Het getal erna is het nummer van de wagen (bijvoorbeeld 07-125: regio 07, wagen 125).', 'Ambulance. The number after it is the vehicle (for example 07-125: region 07, vehicle 125).', 'ambulance', false],
    ['Rit', 'Ritnummer van de ambulance-inzet. Meldingen met hetzelfde ritnummer horen bij dezelfde inzet.', 'Ride number of the ambulance call. Alerts with the same ride number belong to the same call.', 'ambulance', false],
    ['VWS', 'Voorwaardenscheppende rit: een ambulance verplaatst naar een andere post, zodat de omgeving gedekt blijft als andere wagens bezet zijn. Geen spoedgeval op die plek.', 'Coverage move: an ambulance relocates to another station so the area stays covered while other vehicles are busy. Not an emergency at that place.', 'ambulance', true],
    ['MMT', 'Mobiel Medisch Team: een arts en verpleegkundige die met de traumahelikopter of een auto naar zware ongevallen gaan.', 'Mobile Medical Team: a doctor and nurse who go to serious incidents by trauma helicopter or car.', 'ambulance', true],
    ['Lifeliner', 'Traumahelikopter van het MMT. Lifeliner 1 tot en met 4 hebben elk een eigen basis.', 'Trauma helicopter of the MMT. Lifeliner 1 to 4 each have their own base.', 'ambulance', false],
    ['Reanimatie', 'Iemand heeft een hartstilstand. Ambulance, en vaak ook brandweer of politie met een AED, gaan met spoed.', 'Someone is in cardiac arrest. Ambulance, often with fire service or police bringing an AED, respond urgently.', null, false],
    ['AED', 'Automatische externe defibrillator: apparaat dat het hart met een schok weer in een goed ritme kan brengen.', 'Automated external defibrillator: device that can shock the heart back into a normal rhythm.', null, true],
    ['MKA', 'Meldkamer ambulancezorg.', 'Ambulance dispatch centre.', 'ambulance', true],
    ['OvD-G', 'Officier van Dienst Geneeskundig: leidt de medische hulp bij grotere incidenten.', 'Medical duty officer: leads medical care at larger incidents.', 'ambulance', false],
    ['SEH', 'Spoedeisende hulp van een ziekenhuis.', 'Emergency department of a hospital.', 'ambulance', true],
    ['ZKH', 'Ziekenhuis.', 'Hospital.', 'ambulance', true],
    ['Besteld vervoer', 'Ambulancevervoer dat vooraf is afgesproken, zonder spoed.', 'Ambulance transport arranged in advance, without urgency.', 'ambulance', false],
    // Fire service: vehicles and roles
    ['TS', 'Tankautospuit: de standaard brandweerwagen met water, pompen en zes brandweerlieden.', 'Fire engine (pumper) with water, pumps and a crew of six.', 'brandweer', true],
    ['TS4', 'Tankautospuit met een bemanning van vier.', 'Fire engine with a crew of four.', 'brandweer', true],
    ['TS6', 'Tankautospuit met een bemanning van zes.', 'Fire engine with a crew of six.', 'brandweer', true],
    ['AL', 'Autoladder: brandweerwagen met een lange ladder om hoog te werken en mensen te redden.', 'Aerial ladder truck to work at height and rescue people.', 'brandweer', true],
    ['HW', 'Hoogwerker: brandweerwagen met een uitschuifbare arm en werkbak.', 'Aerial platform truck with an extending boom and basket.', 'brandweer', true],
    ['HV', 'Hulpverleningsvoertuig: brandweerwagen met zwaar gereedschap om mensen te bevrijden, bijvoorbeeld na een ongeval.', 'Heavy rescue vehicle with tools to free trapped people, for example after a crash.', 'brandweer', true],
    ['HV weg', 'Hulpverlening op de weg, meestal een verkeersongeval.', 'Rescue on the road, usually a traffic accident.', null, false],
    ['RV', 'Redvoertuig: voor zware technische hulpverlening, zoals vrachtwagens of bussen.', 'Heavy rescue truck, for example for lorries or buses.', 'brandweer', true],
    ['WO', 'Waterongevallen: brandweereenheid met duikers of een boot voor mensen of voertuigen te water.', 'Water rescue unit with divers or a boat, for people or vehicles in the water.', 'brandweer', true],
    ['DA', 'Dienstauto van de brandweer.', 'Fire service car.', 'brandweer', true],
    ['DV', 'Dienstvoertuig, bijvoorbeeld van een officier.', 'Service vehicle, for example of an officer.', 'brandweer', true],
    ['OvD', 'Officier van Dienst: leidt de brandweerinzet ter plaatse bij grotere incidenten.', 'Duty officer: leads the fire service on scene at larger incidents.', 'brandweer', false],
    ['HOvD', 'Hoofdofficier van Dienst: leidt de brandweer bij zeer grote incidenten.', 'Senior duty officer: leads the fire service at very large incidents.', 'brandweer', false],
    ['CvD', 'Commandant van Dienst: de hoogste leidinggevende van de brandweer bij een ramp of crisis.', 'Fire service commander at a disaster or crisis.', 'brandweer', false],
    ['AGS', 'Adviseur Gevaarlijke Stoffen: specialist bij incidenten met chemische of gevaarlijke stoffen.', 'Hazardous materials adviser.', 'brandweer', true],
    ['Bevelvoerder', 'Leidinggevende van een brandweerwagen.', 'Officer in charge of a fire engine.', 'brandweer', false],
    ['Kazernecode', 'Alarmeringscode voor een hele brandweerpost: de vrijwilligers van die post komen naar de kazerne.', 'Alert code for a whole fire station: its volunteers come to the station.', 'brandweer', false],
    ['Herbezetting', 'Een brandweerpost wordt tijdelijk bezet door een andere ploeg, omdat de eigen ploeg elders bezig is.', 'A fire station is temporarily staffed by another crew while its own crew is busy elsewhere.', 'brandweer', false],
    ['Kazerneren', 'De brandweer gaat naar de kazerne en wacht daar in gereedheid.', 'The fire crew goes to the station and stands by there.', 'brandweer', false],
    ['Proefalarm', 'Test van de alarmering. Geen echte inzet.', 'Test of the alerting system. Not a real call.', null, false],
    // Fire service: kinds of calls
    ['BMI', 'Brandmeldinstallatie: een automatische brandmelder in een gebouw is afgegaan. Vaak loos alarm, maar de brandweer gaat altijd kijken.', 'Automatic fire alarm in a building went off. Often a false alarm, but the fire service always checks.', 'brandweer', true],
    ['OMS', 'Openbaar meldsysteem: de automatische brandmelding komt rechtstreeks bij de meldkamer binnen.', 'Automatic fire alarm connected directly to the dispatch centre.', 'brandweer', true],
    ['Middelbrand', 'Brand waarvoor meer dan twee brandweerwagens nodig zijn.', 'Fire that needs more than two fire engines.', 'brandweer', false],
    ['Grote brand', 'Brand waarvoor veel brandweerwagens nodig zijn.', 'Fire that needs many fire engines.', 'brandweer', false],
    ['Zeer grote brand', 'Zeer grote brand met inzet uit een groot gebied.', 'Very large fire with crews from a wide area.', 'brandweer', false],
    ['Buitenbrand', 'Brand in de open lucht, zoals berm, bos, container of afval.', 'Outdoor fire, for example verge, forest, container or rubbish.', 'brandweer', false],
    ['Liftopsluiting', 'Mensen zitten vast in een lift.', 'People are trapped in a lift.', 'brandweer', false],
    ['Ongeval wegvervoer', 'Verkeersongeval op de weg.', 'Road traffic accident.', null, false],
    ['Ongeval water', 'Ongeval op of in het water, bijvoorbeeld een persoon of auto te water.', 'Incident on or in the water, for example a person or car in the water.', null, false],
    ['Beknelling', 'Iemand zit vast, bijvoorbeeld in een voertuig na een ongeval.', 'Someone is trapped, for example in a vehicle after a crash.', null, false],
    ['Assistentie ambulance', 'De brandweer helpt de ambulance, bijvoorbeeld met tillen of een reanimatie.', 'The fire service helps the ambulance crew, for example with lifting or a resuscitation.', 'brandweer', false],
    ['Assistentie politie', 'De brandweer helpt de politie.', 'The fire service helps the police.', 'brandweer', false],
    ['Nacontrole', 'De brandweer komt terug om te controleren of een brand echt uit is.', 'The fire service returns to check that a fire is really out.', 'brandweer', false],
    ['Gaslekkage', 'Er ruikt of lekt gas.', 'Gas smell or leak.', 'brandweer', false],
    ['CO-melder', 'Koolmonoxidemelder is afgegaan. Koolmonoxide is een giftig gas dat je niet ruikt.', 'Carbon monoxide alarm went off. CO is a poisonous gas you cannot smell.', 'brandweer', false],
    ['Dier in problemen', 'Een dier zit vast of is in gevaar.', 'An animal is stuck or in danger.', 'brandweer', false],
    ['Stormschade', 'Schade door wind, zoals een omgewaaide boom of losse dakpannen.', 'Wind damage, such as a fallen tree or loose roof tiles.', 'brandweer', false],
    ['Wateroverlast', 'Te veel water op straat of in een gebouw.', 'Flooding in the street or a building.', 'brandweer', false],
    // Scaling up
    ['GRIP', 'Gecoördineerde Regionale Incidentbestrijdings Procedure: de hulpdiensten schalen op en werken samen in één aanpak. GRIP 1 is ter plaatse, GRIP 2 ook rond het incident, GRIP 3 voor een gemeente, GRIP 4 voor meer gemeenten, GRIP 5 voor meer regio\'s, GRIP Rijk landelijk.', 'Coordinated regional incident procedure: services scale up and work as one. GRIP 1 on scene, GRIP 2 also around the incident, GRIP 3 for a municipality, GRIP 4 for several municipalities, GRIP 5 for several regions, GRIP Rijk nationwide.', null, true],
    ['Opschaling', 'Er worden meer eenheden bijgeroepen, omdat het incident groter is dan eerst gedacht.', 'More units are called in because the incident is bigger than first thought.', null, false],
    ['CoPI', 'Commando Plaats Incident: het overleg van alle hulpdiensten bij een groot incident.', 'On-scene command team of all services at a large incident.', null, false],
    // Police
    ['MKP', 'Meldkamer politie.', 'Police dispatch centre.', 'politie', true],
    ['Assistentie collega', 'Agenten vragen om hulp van collega\'s.', 'Officers ask colleagues for backup.', 'politie', false],
    ['Aanrijding letsel', 'Verkeersongeval met gewonden.', 'Traffic accident with injuries.', null, false],
    ['Aanrijding materieel', 'Verkeersongeval met alleen schade.', 'Traffic accident with damage only.', null, false],
    ['VKO', 'Verkeersongeval.', 'Traffic accident.', null, true],
    // Dispatch / general
    ['GMS', 'Geïntegreerd Meldkamer Systeem: het systeem van de meldkamer. Het getal is het incidentnummer; meldingen met hetzelfde nummer horen bij hetzelfde incident.', 'The dispatch system. The number is the incident number; alerts with the same number belong to the same incident.', null, true],
    ['OC', 'Operationeel centrum: de meldkamer.', 'Operations centre: the dispatch centre.', null, true],
    ['MKB', 'Meldkamer brandweer.', 'Fire dispatch centre.', 'brandweer', true],
    ['Graag contact', 'De meldkamer vraagt de eenheid om contact op te nemen. Geen inzet op zich.', 'Dispatch asks the unit to get in touch. Not a call in itself.', null, false],
    ['Capcode', 'Het adres van een pieper: elke eenheid of groep heeft een eigen capcode.', 'The address of a pager: every unit or group has its own capcode.', null, false],
    ['P2000', 'Het landelijke alarmeringsnet waarmee hulpdiensten via piepers worden opgeroepen. De berichten zijn openbaar.', 'The national paging network used to alert emergency services. The messages are public.', null, true],
    // Roads
    ['hm', 'Hectometerpaal: de paaltjes langs snel- en provinciale wegen, elke 100 meter. "hm 21,0" is kilometer 21,0 van die weg.', 'Hectometre post: markers every 100 metres along motorways and provincial roads. "hm 21,0" is kilometre 21.0 of that road.', null, true],
    ['Re', 'Rechts: de rijbaan rechts (in de richting van oplopende hectometers).', 'Right carriageway (in the direction of increasing hectometres).', null, true],
    ['Li', 'Links: de rijbaan links (in de richting van aflopende hectometers).', 'Left carriageway (in the direction of decreasing hectometres).', null, true],
    ['HRR', 'Hoofdrijbaan rechts.', 'Main carriageway, right.', null, true],
    ['HRL', 'Hoofdrijbaan links.', 'Main carriageway, left.', null, true],
  ];
  $items = [];
  foreach ($l as [$term, $nl, $en, $dienst, $hoofd]) $items[] = ['term' => $term, 'uitleg' => $nl, 'uitleg_en' => $en, 'dienst' => $dienst, 'hoofdletters' => $hoofd];
  return ['bijgewerkt' => AA_AFKORTINGEN_VERSIE . 'T00:00:00.000Z', 'items' => $items];
}
