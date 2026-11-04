# Excel 골든 스펙 — `검사기술 업무일보_rev02.xlsx`

The exporter in `app/Services/Excel/` must reproduce this document **cell for cell**.
Everything below was extracted mechanically with `tools/inspect_xlsx.py`; regenerate
the raw dump with:

```sh
python3 tools/inspect_xlsx.py docs/source-rev02.xlsx > /tmp/dump.json
```

The reference file itself lives at `docs/source-rev02.xlsx` and is asserted by
`tests/Feature/ExcelExportGoldenTest.php`.

---

## 1. Workbook

| Property | Value |
|---|---|
| Sheets | `업무일보` (report), `담당자 요약` (per-member summary), `목록` (dropdown lists) |
| Theme font | 맑은 고딕 (Malgun Gothic) — the Korean Windows default |
| Defined names | `구분목록`, `구분목록전체`, `구분표`, `담당자목록`, `담당자목록전체`, `상태목록`, `우선순위목록` |
| Default row height | 16.5 |
| Gridlines | hidden on `업무일보` |
| Zoom | 85 % |

### Defined names (drive the dropdowns in `목록`)

| Name | Refers to |
|---|---|
| `구분목록` | `OFFSET(목록!$B$6,0,0,MAX(1,COUNTA(목록!$B$6:$B$20)),1)` |
| `구분목록전체` | `목록!$B$6:$B$20` |
| `구분표` | `목록!$B$6:$C$20` |
| `담당자목록` | `OFFSET(목록!$I$6,0,0,MAX(1,COUNTA(목록!$I$6:$I$20)),1)` |
| `담당자목록전체` | `목록!$I$6:$I$20` |
| `상태목록` | `OFFSET(목록!$E$6,0,0,MAX(1,COUNTA(목록!$E$6:$E$20)),1)` |
| `우선순위목록` | `OFFSET(목록!$G$6,0,0,MAX(1,COUNTA(목록!$G$6:$G$20)),1)` |

---

## 2. Sheet `업무일보`

### 2.1 Geometry

```
Column widths:  A=2  B=7  C=10  D=13  E=75.5  F=14  G=10  H=12
                I=10 J=15 K=11(merged K:L)  M=16  N=2  O=0(hidden)
Row heights:    1=8.1  2=38.1  3=24  4=9.95  5=21.95  6=21.95  7..11=21
                12=14.1  14=21.95  15=26.1  16..76=21.95
Merged:         B2:M2   B3:C3   G3:H3   L3:M3
                B6:D6   B7:D7   B8:D8   B9:D9   B10:D10   B11:D11
Print:          landscape, A4 (paperSize 9), print_area A1:N76,
                print_title_rows $15:$15,
                margins L/R 0.4, T/B 0.5, header/footer 0.5
Freeze panes:   none
```

> `O` is the helper column `집계용 진행률`. It is hidden but **must exist**: the
> summary block averages it.

### 2.2 Header block (rows 2-3)

| Cell | Content | Style |
|---|---|---|
| `B2` (merged B2:M2) | `="DMT_ 검사 기술 일일업무 보고 / "&TEXT($D$3,"YYMMDD")` | 맑은 고딕 20 bold, `#1F3864`, bottom border medium `#1F3864` |
| `B3` (merged B3:C3) | `보고일` | 10 bold white on `#2F5597`, centered |
| `D3` | report date (date value) | `yy-mm-dd (aaa)`, bold `#1F3864` on `#FFF8E1`, thin `#BFC9D9` box |
| `F3` | `부서` | 10 bold white on `#2F5597` |
| `G3` (merged G3:H3) | `검사 기술` | 10 bold `#1F3864`, thin `#BFC9D9` box |
| `K3` | `작성자` | 10 bold white on `#2F5597` |
| `L3` (merged L3:M3) | author name | 10 bold `#1F3864`, thin `#BFC9D9` box |

### 2.3 Summary block (rows 5-11)

`B5` = `■ 업무 현황 요약` (12 bold `#1F3864`).

Header row 6 (`B6`…`M6`, `B6:D6` merged): fill `#1F3864`, 10 bold white, centered,
thin white left/right borders.

| Col | Header | Column meaning |
|---|---|---|
| B | `구분` | bucket |
| E | `완료율` | completion ratio |
| F | `전체` | total |
| G | `예정` | planned |
| H | `진행 중` | in progress |
| I | `완료` | done |
| J | `평균 진행률` | average progress |
| K | `보류` | on hold |
| L | `지연` | late |
| M | `High 우선` | high priority, unfinished |

Buckets in rows 7-10: `3D AFVI`, `2D AFVI`, `기타`, `목표`.
Row 11 `합계` is bold/filled `#D9E1F2` with medium bottom border `#1F3864`.

Formulas (row `r`, bucket in `$Br`) — **reproduce these literally**:

```
E r  =IFERROR(Ir/Fr,0)                                                   fmt 0%
F r  =COUNTIFS($D$16:$D$76,$Br,$E$16:$E$76,"<>")
G r  =COUNTIFS($D$16:$D$76,$Br,$E$16:$E$76,"<>",$I$16:$I$76,"예정")        fmt 0;-0;"-"
H r  =COUNTIFS(...,$I$16:$I$76,"진행 중")
I r  =COUNTIFS(...,$I$16:$I$76,"완료")
J r  =IFERROR(AVERAGEIFS($O$16:$O$76,$D$16:$D$76,$Br,$E$16:$E$76,"<>"),0) fmt 0"%"
K r  =COUNTIFS(...,$I$16:$I$76,"보류")                                     fmt 0;-0;"-"
L r  =COUNTIFS(...,$I$16:$I$76,"<>완료",$L$16:$L$76,"<"&$D$3)               fmt 0;-0;"-"
M r  =COUNTIFS(...,$H$16:$H$76,"High",$I$16:$I$76,"<>완료")                 fmt 0;-0;"-"
E11  =IFERROR(I11/F11,0)     F11..M11 = SUM(F7:F10) style, J11 = IFERROR(AVERAGE($O$16:$O$76),0)
```

Conditional formatting: data bars on `E7:E11` (color `#8EA9DB`) and
`J7:J10 J16:J76` (`#FFC000`), `J11` (`#8EA9DB`). Rule `L7>0` bolds `L7:M11` in
`#C00000`.

### 2.4 Detail block (rows 14-76)

`B14` = `■ 업무 목록`.

Header row 15 (`$15` is also the repeating print title):

| Cell | Header |
|---|---|
| `B15` | `No` |
| `C15` | `업무 키` |
| `D15` | `구분` |
| `E15` | `업무 내용` |
| `F15` | `담당자` |
| `G15` | `보고자` |
| `H15` | `우선순위` |
| `I15` | `상태` |
| `J15` | `진행률` |
| `K15` | `시작일` |
| `L15` | `예정일` |
| `M15` | `비고` |
| `O15` | `집계용 진행률` (hidden helper) |

Style: `#1F3864` fill, 10 bold white, thin white L/R borders, centered, wrapped.

Data rows 16-76 (61 slots):

| Cell | Value / formula | Format | Style |
|---|---|---|---|
| `B` | `=IF(Er="","",COUNTA($E$16:Er))` | | 9 `#8C8C8C`, centered, hair bottom `#BFC9D9` |
| `C` | `=IF($Er="","",IFERROR(VLOOKUP($Dr,구분표,2,FALSE),"W")&"-"&TEXT(COUNTIFS($D$16:$Dr,$Dr,$E$16:$Er,"<>"),"00"))` | | 9 bold `#2F5597` |
| `D` | bucket name | | 10 `#262626`, centered |
| `E` | task text | | 10 `#262626`, left, **wrap**, indent 1 |
| `F` | assignees joined by `, ` | | 10 `#262626`, centered |
| `G` | reporter | | 10 `#262626`, centered |
| `H` | `High` / `Medium` / `Low` | | 10 `#262626`, centered |
| `I` | `예정` / `진행 중` / `완료` / `보류` | | 10 `#262626`, centered |
| `J` | 0…100 | `0"%"` | 10 **bold** `#262626`, centered |
| `K` | start date | `mm/dd (aaa)` | 10 `#262626`, centered |
| `L` | due date or the literal `미정` | `mm/dd (aaa)` | 10 `#262626`, centered |
| `M` | remark | | 9 `#595959`, left, wrap, indent 1 |
| `O` | `=IF($Er="","",IF($Ir="완료",100,IF($Jr="","",$Jr)))` | | 11 맑은 고딕, theme1 |

Border: `hair` bottom `#BFC9D9` on every data cell; the **last** used row switches
to `thin` `#1F3864` (row 76 in the template, row `16+N-1` in a generated file).

Conditional formatting on the detail block (dxf palette in §5):

| Range | Rule | Effect |
|---|---|---|
| `B16:M76` | `$I16="완료"` | fill `#F3F6FB`, font `#8C8C8C` |
| `B16:M76` | `AND($D16<>"",MOD(ROW(),2)=0)` | zebra fill `#F3F6FB` |
| `B16:M44 B46:M76` | `AND($D16<>"",$D16<>$D15,ROW()>16)` | thin top border `#2F5597` — separates bucket groups |
| `D16:D76` | `$D16="3D AFVI"` / `"2D AFVI"` / `"기타"` / `"목표"` | bucket tints `#E2EFDA` / `#DDEBF7` / `#EDEDED` / `#FCE4D6` |
| `H16:H76` | `$H16="High"` / `"Medium"` / `"Low"` | bold `#C00000` / `#B07D00` / plain |
| `I16:I76` | `완료` / `진행 중` / `예정` / `보류` | green `#375623` / blue `#1F4E79` / grey `#595959` / orange `#C65911` |
| `J16:J76` | data bar 0-100 `#FFC000` | progress bar |
| `J16:J76` | `AND($I16="완료",N($J16)<>100)` | red bold `#C00000` + fill `#FDE9E9` (contradiction) |
| `L16:L76` | `AND($L16<>"",$I16<>"완료",$L16<$D$3)` | red bold `#C00000` — overdue |

Data validation (dropdowns bound to the defined names):

| Range | Type | Source |
|---|---|---|
| `D16:D76` | list | `구분목록` |
| `F16:G76` | list | `담당자목록` |
| `H16:H76` | list | `우선순위목록` |
| `I16:I76` | list | `상태목록` |
| `J16:J76` | whole 0-100 | |
| `K16:L76` | date ≥ 36526 (2000-01-01) | |

---

## 3. Sheet `담당자 요약`

| Cell | Content |
|---|---|
| `B2` (B2:L2) | `="담당자별 업무 요약 / "&TEXT(업무일보!$D$3,"YYMMDD")` |
| `B3` | `업무일보 시트에서 자동 집계 · 공동 담당` |
| `B5` | `■ 담당자별 현황` |
| Row 6 | `담당자 · 전체 · 예정 · 진행 중 · 완료 · 보류 · 지연 · High 미완료 · 평균 진행률 · 완료율` |
| Rows 7-16 | one row per member, `B` = `=IF(INDEX(담당자목록전체,n)="","",INDEX(담당자목록전체,n))`, counts via `COUNTIFS(업무일보!$F$16:$F$76,"*"&$Bn&"*", …)` so **co-assigned rows are counted for both people** |
| `B19` | `■ 담당자 × 구분 (진행 중 / 전체)` |
| Row 20 | `담당자` + one merged pair (`C20:D20`, `E20:F20`, `G20:H20`, `I20:J20`) per bucket, each `=INDEX(구분목록전체,n)&""` |
| Rows 21-30 | `B21`… = `=B7`…, cells render `"진행중/전체"` via `COUNTIFS` |

Column widths: `A=2 B=14 C=8(→D) E=9 F=8(→H) I=11 J=16(→K) L=2`.
Row heights: `1=8.1 2=33.95 3=21.95 5..16=21.95 19=21.95 20=24 21..30=21.95`.
Merged `B2:L2`, `C{r}:D{r}`, `E{r}:F{r}`, `G{r}:H{r}`, `I{r}:J{r}` for r 20-30.

---

## 4. Sheet `목록` (lists / settings)

Three independent dropdown sources side by side, header row 5, values from row 6:

| Col | Header | Values (rows 6-9) |
|---|---|---|
| `B` | `구분` | `3D AFVI`, `2D AFVI`, `기타`, `목표` |
| `C` | `키 약어` | `3D`, `2D`, `ETC`, `GOAL` |
| `E` | `상태` | `예정`, `진행 중`, `완료`, `보류` |
| `G` | `우선순위` | `High`, `Medium`, `Low` |
| `I` | `담당자` | member names, up to row 20 |

`B2` (merged `B2:J2`) = `목록 / 설정`, `B3` = explanatory sentence.
Widths `A=2 B=12 C=9 D=3 E=12 F=3 G=12 H=3 I=14 J=2`.

---

## 5. dxf palette (conditional-formatting styles)

| dxfId | Font | Fill |
|---|---|---|
| 0 | | `#E4DFEC` |
| 1 | | `#FCE4D6` |
| 2 | | `#E2EFDA` |
| 3 | | `#DDEBF7` |
| 4 | bold `#C00000` | |
| 5 | | `#F3F6FB` |
| 6 | bold `#C00000` | |
| 7 | bold `#C00000` | `#FDE9E9` |
| 8 | `#A6A6A6`, numFmt `"100%"` | gradient `#FFC000`→`#FFF6DF`, white thin box |
| 9 | bold `#C65911` | `#FCE4D6` |
| 10 | bold `#595959` | `#EDEDED` |
| 11 | bold `#1F4E79` | `#DDEBF7` |
| 12 | bold `#375623` | `#E2EFDA` |
| 13 | `#8C8C8C` | |
| 14 | bold `#B07D00` | |
| 15 | bold `#C00000` | |
| 16 | bold `#1F3864` | `#E4DFEC` |
| 17 | bold `#1F3864` | `#FCE4D6` |
| 18 | bold `#1F3864` | `#E2EFDA` |
| 19 | bold `#1F3864` | `#DDEBF7` |
| 20 | | top border thin `#2F5597` |
| 21 | | `#F3F6FB` |
| 22 | `#A6A6A6` | |
| 23 | | top border thin `#2F5597` |

---

## 6. Mapping rule: workbook ↔ database

| Workbook column | Database |
|---|---|
| `No` (B) | row ordinal (computed) |
| `업무 키` (C) | `projects.abbr` + `-` + `LPAD(issues.issue_no, 2, '0')` |
| `구분` (D) | `projects.name` |
| `업무 내용` (E) | `issues.summary` |
| `담당자` (F) | `assignees.name` joined with `", "` (primary first, then co-assignees) |
| `보고자` (G) | `reporter.name` |
| `우선순위` (H) | `config('workreport.priorities')[issues.priority_key]` |
| `상태` (I) | `config('workreport.statuses')[issues.status_key]` |
| `진행률` (J) | `issues.percent` (forced to 100 when done, see `O`) |
| `시작일` (K) | `issues.starts_on` |
| `예정일` (L) | `issues.due_on`, or `미정` when `due_undecided` |
| `비고` (M) | `issues.note` |

### Export rules

1. **Never invent rows.** The template has 61 slots; with fewer tasks the surplus
   rows stay formatted but empty, exactly as in the reference file.
2. **Never renumber silently.** `issue_no` is allocated once at creation time
   (`App\Services\IssueKeyGenerator`), unlike the workbook formula.
3. **Formulas stay formulas.** `B`, `C`, `O`, and the whole summary block are
   written as formula strings so Excel recalculates after an edit. Values are
   also written to the cached-value slot so a reader without a calculation engine
   (LibreOffice headless, `openpyxl` with `data_only=True`) still sees numbers.
4. **The date is a date.** `D3` holds a real serial with format `yy-mm-dd (aaa)`,
   never a string — `TEXT($D$3,"YYMMDD")` in `B2` depends on it.
5. **Filename** carries the date and is RFC 5987 encoded:
   `업무일보_YYYYMMDD.xlsx`.
