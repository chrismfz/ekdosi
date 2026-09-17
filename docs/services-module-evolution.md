# Services module — single-model evolution path (ΟΧΙ clone)

> **Απόφαση (2026-09-17, κλειδωμένη μέχρι να υπάρξει λόγος αναθεώρησης):** Κρατάμε **ΕΝΑ**
> module «Υπηρεσίες» (`ServiceContract`) και το **εξελίσσουμε προσθετικά** όταν έρθει η ώρα.
> **ΔΕΝ** κάνουμε clone/duplicate σε ξεχωριστό «Hosting Services» module.

## Το context

Το «Υπηρεσίες» εξυπηρετεί **dual purpose**:

- **Σήμερα (nexon & co.):** «μικρά» επαναλαμβανόμενα — συμβόλαια υποστήριξης, μεταπώληση αδειών
  λογισμικού (πότε λήγουν / πότε πληρωνόμαστε), ένα VM (όπως το ΤΠΥ5 που κουμπώσαμε στο #11).
- **Αύριο (αν η MyIP απογαλακτιστεί από το WHMCS):** shared hosting, VPS/dedicated, «other»
  (π.χ. centova streaming), resellers — με κατηγορίες/υποκατηγορίες, provisioning modules
  (create / suspend / change-package / unsuspend / terminate) και addons (π.χ. Dedicated IP).

Το ερώτημα ήταν: **(α)** ένα module που εξελίσσεται, ή **(β)** clone σε «Hosting Services» με
on/off knob όπως Support / Domains.

## Η απόφαση & το γιατί

**(α) — ένα module, εξέλιξη προσθετική.** Λόγοι:

1. **Ίδιο domain.** Support contract, license resale, VM, shared hosting, VPS, reseller — όλα
   είναι «κάτι που πληρώνεται ανά κύκλο, ανανεώνεται, αναστέλλεται/τερματίζεται, τιμολογείται».
   Ο **πυρήνας είναι πανομοιότυπος**: `StageServiceRenewal` (renewals), `ServiceDunning`
   (auto suspend/terminate), το money path (`InvoiceBalance`), τα analytics (`ServiceContractBilling`),
   το myDATA lifecycle. Αυτό που διαφέρει ανά περίπτωση = (1) κατηγοριοποίηση και (2) provisioning
   automation — **ιδιότητες/συμπεριφορές μιας υπηρεσίας**, όχι διαφορετική οντότητα.
2. **Το clone διπλασιάζει τα ακριβά, money-critical κομμάτια.** Renewals, dunning, χρήμα, myDATA,
   analytics θα υπήρχαν σε **δύο αντίγραφα σε μόνιμο συγχρονισμό**: κάθε bug fix / αλλαγή myDATA spec /
   review finding, **δύο φορές**. Χειρότερη δυνατή θέση για duplication (CLAUDE.md: fix at the root).
3. **Το μοντέλο είναι ΗΔΗ χτισμένο γι' αυτό.** Το `ServiceContract` έχει τον provisioning seam:
   `provisioning_module` + `module_meta` (JSON) + `server_id` + `ProvisioningModule` (docblock: «the
   future native, WHMCS-independent automation hooks»). Το WHMCS αποδεικνύει ότι **ένα** «Products &
   Services» μοντέλο καλύπτει support/licenses/hosting/VPS/resellers/addons με modules από πάνω.

## Πού έχει δίκιο το (β) — και η σωστή απάντηση

Η βάσιμη ανησυχία πίσω από το clone: *μήπως οι hosting-specific ανάγκες φουσκώσουν το απλό UX που
θέλει η nexon για ένα support contract;* Η απάντηση **δεν** είναι δεύτερο module — είναι
**progressive disclosure μέσα στο ένα**:

- Ένα **`service_type` / κατηγορία** στο ίδιο το `service_contracts` (τα manual services δεν έχουν
  πάντα product). Το type οδηγεί ποια πεδία/tabs/modules φαίνονται: «Υποστήριξη» → μόνο billing +
  ημερομηνίες· «Shared hosting» → module + package + addons. Η nexon βλέπει απλά, η MyIP βλέπει βάθος,
  **ίδιος πυρήνας**.
- **Addons (Dedicated IP κ.λπ.)** = child rows (self-reference `parent_id`) ή/και extra invoice lines —
  κάθε addon ένα μίνι recurring service. Επέκταση, όχι fork.
- **Κατηγορίες/υποκατηγορίες**: πάνω στο υπάρχον `ProductCategory` (ιεραρχικό) για τα product-linked,
  + το `service_type` για τα manual.

## Το on/off knob είναι ορθογώνιο

Το Support/Domains πήραν knob γιατί είναι **όντως άλλα domains** (registrar/TLD/WHOIS·
departments/IMAP/threads). Οι hosting υπηρεσίες **δεν** είναι άλλο domain — είναι το ίδιο με
περισσότερο provisioning. Αν παρ' όλα αυτά θέλουμε per-tenant visibility (μια εταιρεία να μη βλέπει καν
το nav «Υπηρεσίες»), **μπορούμε να έχουμε knob ΚΑΙ με το (α)** — κρύβει το nav, δεν διχάζει το μοντέλο.

## Πότε ΘΑ δικαιολογούνταν το (β)

Μόνο αν οι hosting υπηρεσίες απέκλιναν σε **πραγματικά διαφορετικό data model / lifecycle / money
semantics**. Δεν αποκλίνουν (το WHMCS το αποδεικνύει). Άρα το (β) θα πλήρωνε το κόστος του duplication
χωρίς διαφορά domain.

## Η evolution path (προσθετική, όταν έρθει η ώρα της MyIP)

Όλα πάνω στον **έναν** renewal/dunning/money/myDATA πυρήνα:

1. `service_type` (enum) ή/και `service_category_id` στο `service_contracts` + progressive disclosure
   στη φόρμα/προβολή ανά type.
2. **ProvisioningModule drivers** για shared-hosting/VPS/reseller (create/suspend/change-package/
   unsuspend/terminate) πάνω στον υπάρχοντα seam (`provisioning_module`/`module_meta`/`server_id`).
3. **Addons** ως child `service_contracts` (`parent_id`) με δικό τους κύκλο/τιμή/lifecycle.
4. Προαιρετικό per-tenant visibility knob (όπως Support/Domains) — ανεξάρτητο της παραπάνω δομής.

## Τι υπάρχει ήδη (η βάση της εξέλιξης)

`ServiceContract` (κατάλογος + per-cycle price matrix + per-customer contracts) · renewal = staged
DRAFT (ποτέ auto-AADE) · dunning (opt-in ανά προϊόν) · provisioning seam · dashboard MRR/upcoming ·
**#11** billing analytics (`ServiceContractBilling`) + tab «Ανανεώσεις» + retro-link «Σύνδεση
υπάρχοντος παραστατικού». Roadmap: `docs/BACKLOG.md` §10–11.
