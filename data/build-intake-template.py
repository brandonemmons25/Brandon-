"""Build the building intake template Andy fills in."""

from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.worksheet.datavalidation import DataValidation
from openpyxl.utils import get_column_letter

NEIGHBORHOODS = ['Art Museum Area', 'Avenue of the Arts', 'Bella Vista', 'Chinatown',
                 'Fairmount', 'Fishtown', 'Fitler Square', 'Graduate Hospital',
                 'Logan Square', 'Northern Liberties', 'Old City', 'Queen Village',
                 'Rittenhouse Square', 'Society Hill', 'Washington Square',
                 'Washington Square West']
HEIGHT = ['Low-Rise', 'Mid-Rise', 'High-Rise']
CONSTRUCTION = ['New Construction', 'Newer Construction']
STATUS = ['Now Selling', 'Pre-Construction', 'Under Construction', 'Move-In Ready']
VIEWS = ['Skyline', 'River', 'Park', 'City', 'Courtyard']
FEATURES = ['Doorman / Concierge', 'Fitness Center', 'Parking', 'Elevator',
            'Pets Allowed', 'Swimming Pool', 'Outdoor Space', 'Storage']
YESNO = ['Yes', 'No']

FONT = 'Arial'
HEAD_BG = PatternFill('solid', fgColor='2B2B2B')
NOTE_BG = PatternFill('solid', fgColor='F4F2EF')
THIN = Side(style='thin', color='D9D9D9')
BORDER = Border(left=THIN, right=THIN, top=THIN, bottom=THIN)

ROWS = 60  # 49 buildings, with room to spare.

# Column, width, and the note shown under the header.
COLUMNS = [
    ('Building Name', 30, 'Required. Exactly as it should appear on the card.'),
    ('Neighborhood', 24, 'Required. Pick from the list.'),
    ('Street Address', 30, 'Searched alongside the name.'),
    ('Starting At', 18, 'Free text. "Price on request" is fine.'),
    ('Completion Year', 16, 'Year only, e.g. 2026. Drives the Built Date filter.'),
    ('Height', 14, 'Pick from the list.'),
    ('Construction', 20, 'Pick from the list. Leave blank if neither applies.'),
    ('Status', 20, 'Pick from the list.'),
    ('Views', 26, 'One or more, separated by commas. See Lists tab.'),
    ('Features', 34, 'One or more, separated by commas. See Lists tab.'),
    ('Destination URL', 36, 'Where the card links. Blank uses the record\'s own page.'),
    ('Featured', 12, 'Yes or No. Featured buildings lead the directory.'),
    ('Featured Rank', 14, 'Number. Lower shows first. Only for featured rows.'),
]

EXAMPLE = ['The Laurel', 'Rittenhouse Square', '1911 Walnut St', '$2,950,000', 2021,
           'High-Rise', 'New Construction', 'Now Selling', 'Skyline, City',
           'Doorman / Concierge, Fitness Center, Parking, Swimming Pool',
           'https://andyoei.com/the-laurel/', 'Yes', 1]

wb = Workbook()

# ── Read Me ───────────────────────────────────────────────────────────
rm = wb.active
rm.title = 'Read Me'
rm.sheet_view.showGridLines = False
rm.column_dimensions['A'].width = 110

lines = [
    ('Condominium Buildings — Intake', 16, True),
    ('', 11, False),
    ('Fill in the Buildings tab: one row per building, 49 in all.', 11, False),
    ('', 11, False),
    ('Row 2 is a filled-in example. Overwrite it or delete it — it is not imported.', 11, False),
    ('Start your first real building on row 3.', 11, False),
    ('', 11, False),
    ('Only Building Name and Neighborhood are required. Every other column can be left', 11, False),
    ('blank and filled in later; a blank shows as a dash on the card rather than breaking it.', 11, False),
    ('', 11, False),
    ('Columns with a dropdown (Neighborhood, Height, Construction, Status, Featured) only', 11, False),
    ('accept the values in the list. Typing anything else is rejected, which is deliberate —', 11, False),
    ('"Rittenhouse" and "Rittenhouse Square" would otherwise become two separate filters.', 11, False),
    ('', 11, False),
    ('Views and Features hold more than one value, so they take plain text: separate each', 11, False),
    ('with a comma. The Lists tab has the exact spellings. Same rule — spelling must match.', 11, False),
    ('', 11, False),
    ('Destination URL is the page the card opens. Leave it blank until those pages exist.', 11, False),
    ('', 11, False),
    ('Send this back when the rows are in and it gets loaded into the site.', 11, False),
]

for i, (text, size, bold) in enumerate(lines, start=1):
    c = rm.cell(row=i, column=1, value=text)
    c.font = Font(name=FONT, size=size, bold=bold, color='2B2B2B')

# ── Buildings ─────────────────────────────────────────────────────────
ws = wb.create_sheet('Buildings')
ws.sheet_view.showGridLines = False
ws.freeze_panes = 'A3'

for n, (head, width, note) in enumerate(COLUMNS, start=1):
    letter = get_column_letter(n)
    ws.column_dimensions[letter].width = width

    h = ws.cell(row=1, column=n, value=head)
    h.font = Font(name=FONT, size=10, bold=True, color='FFFFFF')
    h.fill = HEAD_BG
    h.alignment = Alignment(vertical='center', wrap_text=True)
    h.comment = None

ws.row_dimensions[1].height = 24

for n, value in enumerate(EXAMPLE, start=1):
    c = ws.cell(row=2, column=n, value=value)
    c.font = Font(name=FONT, size=10, italic=True, color='83838B')
    c.fill = NOTE_BG
    c.border = BORDER
    c.alignment = Alignment(vertical='center')

for row in range(3, ROWS + 3):
    for n in range(1, len(COLUMNS) + 1):
        c = ws.cell(row=row, column=n)
        c.font = Font(name=FONT, size=10, color='2B2B2B')
        c.border = BORDER
        c.alignment = Alignment(vertical='center')

# ── Lists ─────────────────────────────────────────────────────────────
ls = wb.create_sheet('Lists')
ls.sheet_view.showGridLines = False

VOCAB = [('Neighborhood', NEIGHBORHOODS), ('Height', HEIGHT),
         ('Construction', CONSTRUCTION), ('Status', STATUS),
         ('Views', VIEWS), ('Features', FEATURES), ('Featured', YESNO)]

for n, (head, values) in enumerate(VOCAB, start=1):
    letter = get_column_letter(n)
    ls.column_dimensions[letter].width = max(len(head), max(len(v) for v in values)) + 4

    h = ls.cell(row=1, column=n, value=head)
    h.font = Font(name=FONT, size=10, bold=True, color='FFFFFF')
    h.fill = HEAD_BG

    for i, v in enumerate(values, start=2):
        c = ls.cell(row=i, column=n, value=v)
        c.font = Font(name=FONT, size=10, color='2B2B2B')

note = ls.cell(row=len(NEIGHBORHOODS) + 4, column=1,
               value='These are the values the site already knows. Adding one here does not '
                     'create it on the site — ask first if a building needs something new.')
note.font = Font(name=FONT, size=10, italic=True, color='83838B')

# ── Dropdowns ─────────────────────────────────────────────────────────
# Column on Buildings -> column on Lists holding its vocabulary.
DROPDOWNS = [('B', 'A', len(NEIGHBORHOODS)), ('F', 'B', len(HEIGHT)),
             ('G', 'C', len(CONSTRUCTION)), ('H', 'D', len(STATUS)),
             ('L', 'G', len(YESNO))]

for target, source, count in DROPDOWNS:
    dv = DataValidation(
        type='list',
        formula1='Lists!${0}$2:${0}${1}'.format(source, count + 1),
        allow_blank=True,
        showDropDown=False,  # False means "show the arrow" in the file format.
    )
    dv.showErrorMessage = True
    dv.error = 'Pick a value from the dropdown. Anything else would create a duplicate filter.'
    dv.errorTitle = 'Not on the list'
    ws.add_data_validation(dv)
    dv.add('{0}2:{0}{1}'.format(target, ROWS + 2))

wb.save('/home/user/Brandon-/data/Andy-Oei-Buildings-Intake.xlsx')
print('written')
