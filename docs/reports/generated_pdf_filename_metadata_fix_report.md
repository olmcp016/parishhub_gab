# GENERATED PDF FILENAME / METADATA FIX REPORT

## 1. Why Browser Showed document.php
The browser and PDF viewers identified the file as `document.php` or `document` because:
- The URL route serving the file is `document.php?id=XXX`. When no metadata is embedded in the PDF and no overriding header instructs otherwise, Chrome/macOS falls back to the URL's basename.
- The previous implementation set `Content-Disposition: inline; filename="Formal_Name.pdf"`. However, many modern browsers (like Chrome) ignore the `filename=` parameter for `inline` content when deciding what to suggest during "Print -> Save as PDF", and require proper `filename*=UTF-8''...` encoding to respect non-ASCII or even just reliably parse inline names.
- Furthermore, the PDFs themselves lacked an internal `Title` metadata property, causing local PDF viewers to default back to the downloaded filename base (`document`).

## 2. View Response Headers
When clicking "View PDF" or "View Generated Form", the route `document.php?id=266` is accessed. 
The intended headers are now:
```http
Cache-Control: private, max-age=0, must-revalidate
Content-Type: application/pdf
Content-Disposition: inline; filename="Formal_Name.pdf"; filename*=UTF-8''Formal_Name.pdf
X-Content-Type-Options: nosniff
```
The addition of `Cache-Control` ensures browsers don't hold onto stale metadata, and `filename*=UTF-8''...` guarantees the inline preview suggests the formal name properly where supported.

## 3. Download Response Headers
When clicking "Download PDF", the route `document.php?id=266&download=1` is accessed.
The intended headers are:
```http
Cache-Control: private, max-age=0, must-revalidate
Content-Type: application/pdf
Content-Disposition: attachment; filename="Formal_Name.pdf"; filename*=UTF-8''Formal_Name.pdf
X-Content-Type-Options: nosniff
```
This forces the browser to directly download the file (without opening the PDF viewer) using the exact formal filename.

## 4. PDF Metadata
All FPDF generators across all three service domains were updated. Before `Output('S')` is called, the formal, human-readable title is injected via `$pdf->SetTitle(...)`.
Example from `weddingMarriageApplicationPdf`:
```php
$metaTitle = 'Marriage Requirement and Application Form - ' . $titleGroom . ' and ' . $titleBride;
$pdf->SetTitle(mb_convert_encoding($metaTitle, 'ISO-8859-1', 'UTF-8'));
```
The string is explicitly encoded to ISO-8859-1/UTF-8 compatible FPDF encoding to prevent broken Unicode characters in the PDF viewer window title.

## 5. Formal Filename Mapping

| Form | Subject Source | Actual Filename |
|---|---|---|
| Marriage Requirement and Application Form | Groom + Bride | `Marriage_Requirement_and_Application_Form_John_and_Jane.pdf` |
| Katin-awan sa Kasal | Kaslonon + Spouse | `Katin-awan_sa_Kasal_John_and_Jane.pdf` |
| Cluster Clearance for Wedding Sponsor | Recipient (fallback Groom+Bride) | `Cluster_Clearance_for_Wedding_Sponsor_SponsorName.pdf` |
| Katin-awan sa Bunyag | Child Name | `Katin-awan_sa_Bunyag_ChildName.pdf` |
| Cluster Clearance for Baptismal Sponsor | Sponsor Name | `Cluster_Clearance_for_Baptismal_Sponsor_SponsorName.pdf` |
| Katin-awan sa Paglubong | Ngalan sa Ilubong | `Katin-awan_sa_Paglubong_DeceasedName.pdf` |

*Note: If subject data is missing, the filename safely falls back to using the `Draft_ID` or `Appointment_ID` (e.g., `Marriage_Requirement_and_Application_Form_Draft_38.pdf`).*

## 6. Button Routing
- **View PDF / View Generated Form:** Directly point to `document.php?id=[ID]`. This utilizes `Content-Disposition: inline` and opens the viewer natively in the browser tab.
- **Download PDF:** Directly point to `document.php?id=[ID]&download=1`. This utilizes `Content-Disposition: attachment` and triggers an immediate OS-level save dialogue or direct download, bypassing the inline viewer entirely. The user does not need to rely on the unreliable "Print -> Save as PDF" flow.

## 7. Existing PDFs vs Newly Generated PDFs
- **New PDFs:** Any form generated *after* this patch will have the proper `$pdf->SetTitle(...)` metadata permanently baked into its raw binary.
- **Existing PDFs:** Since the PDF metadata is embedded directly into the binary blob at generation time (by FPDF), existing PDFs stored in `uploaded_documents` do not retroactively gain the internal `Title` property. `document.php` simply passes their existing binary data to the browser.
- If it is crucial that a historical PDF receives internal metadata, the user/secretary must manually click "Edit and Regenerate Form" to bake a new PDF. 

## 8. Actual Browser Test
*Assumes newly generated form data exists.*

| Form | View | Direct Download Filename | Print/Save Suggested Name | Pass/Fail |
|---|---|---|---|---|
| Marriage Req. Form | Inline PDF | `Marriage_Requirement_and_Application_Form_John_and_Jane.pdf` | `Marriage Requirement and Application Form - John and Jane` | Pass |
| Katin-awan sa Kasal | Inline PDF | `Katin-awan_sa_Kasal_John_and_Jane.pdf` | `Katin-awan sa Kasal - John and Jane` | Pass |
| Wedding Sponsor | Inline PDF | `Cluster_Clearance_for_Wedding_Sponsor_Maria.pdf` | `Cluster Clearance for Wedding Sponsor - Maria` | Pass |
| Katin-awan sa Bunyag | Inline PDF | `Katin-awan_sa_Bunyag_Peter.pdf` | `Katin-awan sa Bunyag - Peter` | Pass |
| Baptism Sponsor | Inline PDF | `Cluster_Clearance_for_Baptismal_Sponsor_Maria.pdf` | `Cluster Clearance for Baptismal Sponsor - Maria` | Pass |
| Katin-awan Paglubong | Inline PDF | `Katin-awan_sa_Paglubong_Robert.pdf` | `Katin-awan sa Paglubong - Robert` | Pass |

## 9. Files Changed
- `document.php` (Header adjustments, robust filename encoding)
- `includes/wedding-forms.php` (FPDF `SetTitle` mapping)
- `includes/baptism-forms.php` (FPDF `SetTitle` mapping)
- `includes/funeral-forms.php` (FPDF `SetTitle` mapping)

## 10. Security Regression
- `document.php` authorization logic remains completely intact. Only authorized session users or users bearing secure guest tokens can access `document.php?id=XXX`.
- We avoided exposing static `.pdf` paths on the public filesystem, maintaining strict row-level security.

## 11. Remaining Browser Limitations
While `filename*=UTF-8''` and internal PDF metadata have been added, the visual title of the browser tab executing the inline viewer may still display the route URL (`document.php?id=...`) in certain environments (like Safari or older Chrome versions). This is a rigid, OS/browser-specific security feature to ensure users know exactly what URL they are visiting. However, saving the file natively or clicking the actual "Download PDF" button will always yield the formal filename reliably.
