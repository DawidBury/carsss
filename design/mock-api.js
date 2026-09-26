// Mock warstwy danych dla ilejedzie.pl.
// Podmień ciała funkcji na fetch() do backendu — kontrakt (kształt zwracanych
// obiektów) zostaw bez zmian i front nie wymaga żadnych poprawek.
//
//   GET /api/catalog                                  -> Brand[]
//   GET /api/version?marka=bmw&model=e91&wersja=325xi-at -> VersionResult

const G_ENGINE = "Silnik";
const G_DRIVE = "Napęd i skrzynia";
const G_PERF = "Osiągi";

const v = (id, name, body, engine, drive, perf) => ({
  id, name, body,
  specs: { [G_ENGINE]: engine, [G_DRIVE]: drive, [G_PERF]: perf },
});

export const CATALOG = [
  {
    id: "bmw", name: "BMW", models: [
      {
        id: "e91", name: "Seria 3 Touring (E91)", years: "2005–2012", alias: "e91 seria 3 touring kombi 3er",
        versions: [
          v("320i-mt", "320i Manual", "kombi",
            [["Kod silnika", "N46B20"], ["Pojemność", "1 995 cm³"], ["Moc", "150 KM (110 kW) @ 6 200"], ["Moment", "200 Nm @ 3 600"], ["Układ", "R4, benzyna, wolnossący"]],
            [["Napęd", "RWD"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "9,4 s"], ["Prędkość maks.", "215 km/h"]]),
          v("325xi-at", "325xi Automat", "kombi",
            [["Kod silnika", "N52B25"], ["Pojemność", "2 497 cm³"], ["Moc", "218 KM (160 kW) @ 6 500"], ["Moment", "250 Nm @ 2 750"], ["Układ", "R6, benzyna, wolnossący"]],
            [["Napęd", "xDrive (stały 4×4)"], ["Skrzynia", "6-bieg. automatyczna"]],
            [["0–100 km/h", "7,4 s"], ["Prędkość maks.", "238 km/h"]]),
          v("325i-mt", "325i Manual", "kombi",
            [["Kod silnika", "N52B25"], ["Pojemność", "2 497 cm³"], ["Moc", "218 KM (160 kW) @ 6 500"], ["Moment", "250 Nm @ 2 750"], ["Układ", "R6, benzyna, wolnossący"]],
            [["Napęd", "RWD"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "7,0 s"], ["Prędkość maks.", "245 km/h"]]),
          v("330d-at", "330d Automat", "kombi",
            [["Kod silnika", "M57D30TÜ2"], ["Pojemność", "2 993 cm³"], ["Moc", "231 KM (170 kW) @ 4 000"], ["Moment", "500 Nm @ 1 750"], ["Układ", "R6, diesel, turbo"]],
            [["Napęd", "RWD"], ["Skrzynia", "6-bieg. automatyczna"]],
            [["0–100 km/h", "6,9 s"], ["Prędkość maks.", "245 km/h"]]),
        ],
      },
      {
        id: "e46", name: "Seria 3 (E46)", years: "1998–2006", alias: "e46 seria 3 sedan 3er",
        versions: [
          v("318i-mt", "318i Manual", "sedan",
            [["Kod silnika", "N42B20"], ["Pojemność", "1 995 cm³"], ["Moc", "143 KM (105 kW) @ 6 000"], ["Moment", "200 Nm @ 3 750"], ["Układ", "R4, benzyna, Valvetronic"]],
            [["Napęd", "RWD"], ["Skrzynia", "5-bieg. manualna"]],
            [["0–100 km/h", "9,3 s"], ["Prędkość maks.", "215 km/h"]]),
          v("330i-mt", "330i Manual", "sedan",
            [["Kod silnika", "M54B30"], ["Pojemność", "2 979 cm³"], ["Moc", "231 KM (170 kW) @ 5 900"], ["Moment", "300 Nm @ 3 500"], ["Układ", "R6, benzyna, wolnossący"]],
            [["Napęd", "RWD"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "6,5 s"], ["Prędkość maks.", "250 km/h (ogr.)"]]),
          v("320d-mt", "320d Manual", "sedan",
            [["Kod silnika", "M47D20TÜ"], ["Pojemność", "1 995 cm³"], ["Moc", "150 KM (110 kW) @ 4 000"], ["Moment", "330 Nm @ 2 000"], ["Układ", "R4, diesel, turbo"]],
            [["Napęd", "RWD"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "8,9 s"], ["Prędkość maks.", "218 km/h"]]),
        ],
      },
    ],
  },
  {
    id: "audi", name: "Audi", models: [
      {
        id: "b7", name: "A4 B7", years: "2004–2008", alias: "b7 a4 8e sedan avant",
        versions: [
          v("2-0-tdi-mt", "2.0 TDI Manual", "sedan",
            [["Kod silnika", "BRE / BLB"], ["Pojemność", "1 968 cm³"], ["Moc", "140 KM (103 kW) @ 4 000"], ["Moment", "320 Nm @ 1 750"], ["Układ", "R4, diesel, turbo"]],
            [["Napęd", "FWD"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "9,4 s"], ["Prędkość maks.", "209 km/h"]]),
          v("2-0-tfsi-quattro-mt", "2.0 TFSI quattro Manual", "sedan",
            [["Kod silnika", "BWE / BGB"], ["Pojemność", "1 984 cm³"], ["Moc", "200 KM (147 kW) @ 5 100"], ["Moment", "280 Nm @ 1 800"], ["Układ", "R4, benzyna, turbo"]],
            [["Napęd", "quattro (stały 4×4)"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "7,1 s"], ["Prędkość maks.", "237 km/h"]]),
          v("3-2-fsi-quattro-at", "3.2 FSI quattro Tiptronic", "sedan",
            [["Kod silnika", "AUK"], ["Pojemność", "3 123 cm³"], ["Moc", "255 KM (188 kW) @ 6 500"], ["Moment", "330 Nm @ 3 250"], ["Układ", "V6, benzyna, FSI"]],
            [["Napęd", "quattro (stały 4×4)"], ["Skrzynia", "6-bieg. automatyczna"]],
            [["0–100 km/h", "6,6 s"], ["Prędkość maks.", "250 km/h (ogr.)"]]),
        ],
      },
    ],
  },
  {
    id: "volkswagen", name: "Volkswagen", models: [
      {
        id: "golf-v", name: "Golf V (1K)", years: "2003–2009", alias: "golf 5 v 1k hatchback",
        versions: [
          v("1-9-tdi-mt", "1.9 TDI Manual", "hatchback",
            [["Kod silnika", "BXE"], ["Pojemność", "1 896 cm³"], ["Moc", "105 KM (77 kW) @ 4 000"], ["Moment", "250 Nm @ 1 900"], ["Układ", "R4, diesel, pompowtryskiwacze"]],
            [["Napęd", "FWD"], ["Skrzynia", "5-bieg. manualna"]],
            [["0–100 km/h", "11,3 s"], ["Prędkość maks.", "188 km/h"]]),
          v("gti-dsg", "GTI 2.0 TFSI DSG", "hatchback",
            [["Kod silnika", "AXX / BWA"], ["Pojemność", "1 984 cm³"], ["Moc", "200 KM (147 kW) @ 5 100"], ["Moment", "280 Nm @ 1 800"], ["Układ", "R4, benzyna, turbo"]],
            [["Napęd", "FWD"], ["Skrzynia", "6-bieg. DSG"]],
            [["0–100 km/h", "7,0 s"], ["Prędkość maks.", "235 km/h"]]),
        ],
      },
      {
        id: "passat-b6", name: "Passat B6 (3C)", years: "2005–2010", alias: "passat b6 3c sedan kombi",
        versions: [
          v("2-0-tdi-mt", "2.0 TDI Manual", "sedan",
            [["Kod silnika", "BKP"], ["Pojemność", "1 968 cm³"], ["Moc", "140 KM (103 kW) @ 4 000"], ["Moment", "320 Nm @ 1 750"], ["Układ", "R4, diesel, turbo"]],
            [["Napęd", "FWD"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "9,8 s"], ["Prędkość maks.", "207 km/h"]]),
        ],
      },
    ],
  },
  {
    id: "toyota", name: "Toyota", models: [
      {
        id: "e120", name: "Corolla E120", years: "2001–2007", alias: "e120 corolla hatchback sedan",
        versions: [
          v("1-4-vvti-mt", "1.4 VVT-i Manual", "hatchback",
            [["Kod silnika", "4ZZ-FE"], ["Pojemność", "1 398 cm³"], ["Moc", "97 KM (71 kW) @ 6 000"], ["Moment", "130 Nm @ 4 200"], ["Układ", "R4, benzyna, VVT-i"]],
            [["Napęd", "FWD"], ["Skrzynia", "5-bieg. manualna"]],
            [["0–100 km/h", "12,4 s"], ["Prędkość maks.", "175 km/h"]]),
          v("2-0-d4d-mt", "2.0 D-4D Manual", "hatchback",
            [["Kod silnika", "1CD-FTV"], ["Pojemność", "1 995 cm³"], ["Moc", "116 KM (85 kW) @ 4 000"], ["Moment", "280 Nm @ 2 000"], ["Układ", "R4, diesel, common rail"]],
            [["Napęd", "FWD"], ["Skrzynia", "6-bieg. manualna"]],
            [["0–100 km/h", "10,2 s"], ["Prędkość maks.", "190 km/h"]]),
        ],
      },
    ],
  },
];

export const GROUP_ORDER = [G_ENGINE, G_DRIVE, G_PERF];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** GET /api/catalog */
export async function fetchCatalog({ latency = 350, fail = false } = {}) {
  await sleep(latency);
  if (fail) throw new Error("API_UNAVAILABLE");
  return CATALOG;
}

/** GET /api/version?marka&model&wersja */
export async function fetchVersion({ marka, model, wersja }, { latency = 450, fail = false } = {}) {
  await sleep(latency);
  if (fail) throw new Error("API_UNAVAILABLE");
  const b = CATALOG.find((x) => x.id === marka);
  const m = b && b.models.find((x) => x.id === model);
  const ver = m && m.versions.find((x) => x.id === wersja);
  if (!b || !m || !ver) return null;
  return {
    marka: { id: b.id, name: b.name },
    model: { id: m.id, name: m.name, years: m.years },
    wersja: { id: ver.id, name: ver.name, body: ver.body },
    groups: GROUP_ORDER.map((label) => ({ label, rows: ver.specs[label] || [] })),
  };
}
