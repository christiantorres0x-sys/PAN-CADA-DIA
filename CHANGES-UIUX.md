# UI/UX fixes (from the heuristic evaluation)

**1. Fitts's Law** – buttons min 38-40px (small ones 36px); Present/Absent buttons 38px with 8px gaps; filter-chip "x" and slide-over close enlarged; Save/Cancel in the slide-over form stay pinned at the bottom; Add Beneficiary "Save & Add Another"; filters now in one row (Attendance, Beneficiaries).

**2. Hick's Law** – Baseline section of Add Beneficiary is collapsed behind a checkbox; Program Setup add-forms collapse; Reports has a jump-to bar; sidebar nav no longer looks like the blue action buttons; Quick Actions toned down and reworded as tasks.

**3. GOMS** – "Mark all unrecorded as Present" batch action; name search on Attendance; 20 rows/page; filters auto-apply on dropdown/date change everywhere; one search area on Beneficiaries; duplicate "Pending Enrollments / Baseline Pending" numbers fixed; naming made consistent (Program, Reports & Analysis, Record Measurement).

**4. KLM** – Weight Status is a dropdown (no free typing); realistic height/weight ranges + inline error messages on blur; labels linked to inputs (click label = focus field); login allows password managers; focus moves into/out of the slide-over and stays trapped inside; alert() popups replaced by toasts; developer notes removed from screens; logo 955 KB -> ~100 KB PNG; darker orange/green/red/grey for readable contrast.

**5. Gestalt** – Inter font now loaded on every page; consistent panel padding; larger small-text (table headers 11.5px, body 14px); Name is the first column; Height/Weight/BMI right-aligned; Weight Status column added to Beneficiaries (red bar no longer stands alone); over/obese now orange, under/severe red; pie charts have text alternatives; sidebar narrower (250px, 210px on tablet); mobile: labels shown, Sign Out visible, no forced 400px field width, Site/Program columns hidden on Attendance.

Not changed: sortable table columns, a multi-step wizard for Add Beneficiary, sticky table headers.
