# Ροή έκδοσης μέσω Παρόχου — happy path + fallbacks

> Μονοσέλιδη εποπτεία της ροής (implementation-plan §14). **Write = πάροχος ·
> Read = myDATA.** Renders on GitHub (Mermaid).

## 1. Κύρια ροή (με το status-check loop)

```mermaid
flowchart TD
    A["Οριστικοποίηση<br/>(draft → active)"] --> B{{EInvoiceSubmitterFactory<br/>einvoice_provider?}}
    B -->|gr-mydata| MD["MyDataSubmitter<br/>(απευθείας myDATA)"]
    B -->|gr-provider| C["GrProviderSubmitter"]
    C --> D["AadeInvoiceDocument<br/>(firebed InvoicesDoc v2.0.1)<br/>+ provider extension"]
    D --> E["transport.send(xml, creds)<br/>POST στον Πάροχο"]

    E --> R{Απάντηση;}
    R -->|"✅ Success"| OK["ΜΑΡΚ + authCode + QR"]
    R -->|"❌ ValidationError"| VE["λάθος δεδομένων"]
    R -->|"🔌 down / refused"| DOWN["καμία σύνδεση"]
    R -->|"⏱ timeout ΜΕΤΑ την αποστολή"| TO["αμφίσημο:<br/>ίσως υποβλήθηκε"]

    OK --> P["persist mydata_marks row<br/>(request+response+mark+QR+authCode)<br/>sync invoices.mydata_*<br/>local_status = active"]
    P --> RD["📖 Read path: reconciliation<br/>myDATA RequestTransmittedDocs<br/>= ανεξάρτητος έλεγχος ✅"]

    VE --> VEX["mark row με errors<br/>παραμένει DRAFT"]
    VEX --> FIX["Operator διορθώνει"] --> A

    DOWN --> Q["Queued job:<br/>auto-retry + backoff"]
    Q -->|"παροδικό → πέρασε"| E
    Q -->|"επίμονο outage"| FAIL["failed row +<br/>health banner ⚠"]
    FAIL --> MAN["«Επανυποβολή σε Πάροχο»<br/>(manual)"] --> SC

    TO --> SC{{"status-check ΠΡΩΤΑ<br/>(invoice_status.php)<br/>υπάρχει ΜΑΡΚ;"}}
    SC -->|"ναι"| ADOPT["υιοθέτησε το ΜΑΡΚ<br/>(ΧΩΡΙΣ resend)"] --> P
    SC -->|"όχι"| E

    classDef ok fill:#d6f5d6,stroke:#2e7d32;
    classDef bad fill:#fde2e1,stroke:#c62828;
    classDef guard fill:#fff4cc,stroke:#f9a825;
    class OK,P,RD,ADOPT ok;
    class VE,DOWN,TO,VEX,FAIL bad;
    class SC,Q guard;
```

## 2. Γιατί το status-check είναι το κλειδί (το αμφίσημο timeout)

```mermaid
flowchart LR
    T["⏱ timeout"] --> X{"blind retry;"}
    X -->|"ΟΧΙ ❌"| DD["κίνδυνος<br/>ΔΙΠΛΟ τιμολόγιο"]
    X -->|"ΝΑΙ ✅<br/>status-check first"| Y{"έχει ΜΑΡΚ;"}
    Y -->|ναι| AD["υιοθέτησε"]
    Y -->|όχι| RS["ξαναστείλε με ασφάλεια"]
    classDef bad fill:#fde2e1,stroke:#c62828;
    classDef ok fill:#d6f5d6,stroke:#2e7d32;
    class DD bad;
    class AD,RS ok;
```

## 3. Write vs Read (το μοντέλο σε μία εικόνα)

```mermaid
flowchart LR
    EK["ekdosi"] -->|"WRITE: XML"| PA["Πάροχος"]
    PA -->|"ΜΑΡΚ+auth+QR"| EK
    PA -->|"υποβάλλει για σένα"| MY["myDATA (δικό σου)"]
    EK -. "READ: RequestTransmittedDocs<br/>(reconciliation / έξοδα / ΦΠΑ)" .-> MY
    classDef n fill:#e3f2fd,stroke:#1565c0;
    class EK,PA,MY n;
```

> **Νομικό:** μετά τη «Δήλωση Αποκλειστικής Έκδοσης», ο fallback είναι
> **retry / queue / offline-mode** — **όχι** επιστροφή σε απευθείας myDATA (§14.6).
> **Offline issuance** (`transmissionFailure` 1–4) = ο θεσμοθετημένος fallback όταν
> πέφτει η σύνδεση — deferred v1.
