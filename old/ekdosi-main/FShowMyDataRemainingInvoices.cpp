// ---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop

#include "FShowMyDataRemainingInvoices.h"
#include "CMyData.h"
#include "FPrint.h"

// ---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxClasses"
#pragma link "cxControls"
#pragma link "cxCustomData"
#pragma link "cxData"
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"
#pragma link "cxDataStorage"
#pragma link "cxDBData"
#pragma link "cxEdit"
#pragma link "cxFilter"
#pragma link "cxGraphics"
#pragma link "cxGrid"
#pragma link "cxGridCustomTableView"
#pragma link "cxGridCustomView"
#pragma link "cxGridDBTableView"
#pragma link "cxGridLevel"
#pragma link "cxGridTableView"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma link "cxNavigator"
#pragma link "cxStyles"
#pragma link "JvDotNetControls"
#pragma link "JvExComCtrls"
#pragma link "JvExExtCtrls"
#pragma link "JvExtComponent"
#pragma link "JvPanel"
#pragma link "JvToolBar"
#pragma link "cxCalendar"
#pragma link "cxContainer"
#pragma link "cxDateUtils"
#pragma link "cxDropDownEdit"
#pragma link "cxLabel"
#pragma link "cxMaskEdit"
#pragma link "cxTextEdit"
#pragma link "dxCore"
#pragma link "JvTimer"
#pragma link "cxProgressBar"
#pragma link "JvMenus"
#pragma resource "*.dfm"
TFrmShowMyDataRemainingInvoices *FrmShowMyDataRemainingInvoices;

// ---------------------------------------------------------------------------
__fastcall TFrmShowMyDataRemainingInvoices::TFrmShowMyDataRemainingInvoices(TComponent* Owner) : NewSpecialForm(Owner)
{
 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 dataset = DatasetInvoice;

 defaultSQL = new TStringList();
 defaultSQL->AddStrings(dataset->SelectSQL);

 DatasetInvoice->Open();
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowMyDataRemainingInvoices::cmdSendClick(TObject *Sender)
{
 bool totalRetVal = true;
 int i;
 bool showError = false;

 Progress->Visible = true;
 Progress->Properties->Max = ViewInvoices->DataController->GetSelectedCount();

 if (ViewInvoices->DataController->GetSelectedCount() == 1)
  showError = true;

 for (i = 0; i < ViewInvoices->DataController->GetSelectedCount(); i++)
 {
  Progress->Position = i;
  ViewInvoices->DataController->FocusedRowIndex = ViewInvoices->DataController->GetSelectedRowIndex(i);

  totalRetVal = totalRetVal && sendInvoice(DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger, showError);
  Application->ProcessMessages();
 }

 Progress->Position = i;
 ViewInvoices->DataController->FocusedRowIndex = i;

 if (totalRetVal != true && !showError)
 {
  showMessage("Παρουσιάστηκαν σφάλματα. Εκτελεστε τα εναπομείναντα ένα προς ένα.", MB_ICONERROR);
 }

 Progress->Visible = false;
 ToolRefresh->Click();
}
// ---------------------------------------------------------------------------

bool TFrmShowMyDataRemainingInvoices::sendInvoice(int _invoiceId, bool _printMessage)
{
 std::pair<bool, AnsiString>res;

 try
 {
  std::auto_ptr<MyData>myData(new MyData());
  res = myData->sendInvoice(_invoiceId);
  if (!res.first && _printMessage)
   showMessage(res.second, MB_ICONERROR);
 }
 catch (Exception &e)
 {
  if (_printMessage)
   showMessage(e.Message, MB_ICONERROR);
  return (false);
 }

 return (true);
}

void __fastcall TFrmShowMyDataRemainingInvoices::ViewInvoicesCellDblClick(TcxCustomGridTableView *Sender, TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton, TShiftState AShift,
 bool &AHandled)
{
 TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction, QryInvTypes->FieldByName("FRM_FILENAME")->AsString, "INVOICE_ID", DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger, false);
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::DatasetInvoiceAfterOpen(TDataSet *DataSet)
{
 if (DataSet->RecordCount == 0)
  cmdSend->Enabled = false;
 else
  cmdSend->Enabled = true;

 lblLoading->Visible = false;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::cmdRefreshClick(TObject *Sender)
{
 // DatasetInvoice->Close();
 // DatasetInvoice->Open();
 ShowMessage(DatasetInvoice->SelectSQL->Text);
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::TimerSearchTimer(TObject *Sender)
{
 bool fromValidated = false;
 bool toValidated = false;

 TimerSearch->Enabled = false;

 lblLoading->Visible = true;
 lblLoading->Refresh();

 DatasetInvoice->SelectSQL->Text = defaultSQL->Text;

 DatasetInvoice->Close();
 if (EditFromDate->Text != "")
 {
  try
  {
   TDate dt = StrToDate(EditFromDate->Text);
   fromValidated = true;
  }
  catch (Exception &e)
  {
   fromValidated = false;
  }

  DatasetInvoice->SelectSQL->Insert(DatasetInvoice->SelectSQL->Count - 2, "AND");
  DatasetInvoice->SelectSQL->Insert(DatasetInvoice->SelectSQL->Count - 2, "INVDATE >= CAST(:PERIOD_START AS DATE)");
 }

 if (EditToDate->Text != "")
 {
  try
  {
   TDate dt = StrToDate(EditToDate->Text);
   toValidated = true;
  }
  catch (Exception &e)
  {
   toValidated = false;
  }

  DatasetInvoice->SelectSQL->Insert(DatasetInvoice->SelectSQL->Count - 2, "AND");
  DatasetInvoice->SelectSQL->Insert(DatasetInvoice->SelectSQL->Count - 2, "INVDATE <= CAST(:PERIOD_END AS DATE)");
 }

 ComboStatePropertiesChange(NULL);

 if (EditFromDate->Text == "" || fromValidated)
  if (EditToDate->Text == "" || toValidated)
   DatasetInvoice->Open();

 lblLoading->Visible = false;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::EditFromDatePropertiesChange(TObject *Sender)
{
 TimerSearch->Enabled = false;
 TimerSearch->Enabled = true;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::DatasetInvoiceBeforeOpen(TDataSet *DataSet)
{
 if (EditToDate->Text != "")
 {
  TDate dt;
  try
  {
   dt = StrToDate(EditToDate->Text);
   DatasetInvoice->ParamByName("PERIOD_END")->AsDate = dt;
  }
  catch (Exception &e)
  {

  }
 }

 if (EditFromDate->Text != "")
 {
  TDate dt;
  try
  {
   dt = StrToDate(EditFromDate->Text);
   DatasetInvoice->ParamByName("PERIOD_START")->AsDate = dt;
  }
  catch (Exception &e)
  {

  }
 }
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowMyDataRemainingInvoices::JvDotNetButton1Click(TObject *Sender)
{
 ViewInvoices->DataController->SelectAll();
 cmdSend->Click();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::comboPeriodPropertiesChange(TObject *Sender)
{
 unsigned short day, month, year;
 DecodeDate(Today(), year, month, day);

 switch (comboPeriod->SelectedItem)
 {
 case 0: // current month
  EditFromDate->Date = EncodeDate(year, month, 1);
  break;
 case 1: // Prev month
  EditFromDate->Date = EncodeDate(year, month - 1, 1);
  EditToDate->Date = Dateutils::IncDay(EncodeDate(year, month, 1), -1);
  break;
 case 2: // Current year
  EditFromDate->Date = EncodeDate(year, 1, 1);
  EditToDate->Date = Today();
  break;
 case 3: // Current year
  EditFromDate->Date = EncodeDate(year - 1, 1, 1);
  EditToDate->Date = EncodeDate(year - 1, 12, 31);
  break;
 }
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowMyDataRemainingInvoices::N1Click(TObject *Sender)
{
 cmdSend->Click();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::Suppress1Click(TObject *Sender)
{
 cmdSuppress->Click();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::cmdSuppressClick(TObject *Sender)
{
 int i;
 std::auto_ptr<TIBQuery>qry = getSmartNewQuery();
 qry->SQL->Add("UPDATE INVOICE SET MYDATA_SENT = -1 WHERE INVOICE_ID = :INVOICE_ID");

 Progress->Visible = true;
 Progress->Properties->Max = ViewInvoices->DataController->GetSelectedCount();
 for (i = 0; i < ViewInvoices->DataController->GetSelectedCount(); i++)
 {
  Application->ProcessMessages();
  Progress->Position = i;
  ViewInvoices->DataController->FocusedRowIndex = ViewInvoices->DataController->GetSelectedRowIndex(i);
  qry->ParamByName("INVOICE_ID")->AsInteger = DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger;
  qry->ExecSQL();
 }

 transaction->Commit();
 Progress->Visible = false;
 ToolRefresh->Click();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowMyDataRemainingInvoices::ComboStatePropertiesChange(TObject *Sender)
{
 AnsiString txt;

 switch (ComboState->ItemIndex)
 {
 case 2:
  txt = "WHERE COALESCE(INVOICE.MYDATA_SENT, 0) < 0";
  break;
 case 1:
  txt = "WHERE COALESCE(INVOICE.MYDATA_SENT, 0) > 0";
  break;
 default:
  txt = "WHERE COALESCE(INVOICE.MYDATA_SENT, 0) = 0";
  break;
 }

 DatasetInvoice->SelectSQL->Strings[2] = txt;
 ToolRefresh->Click();
}
// ---------------------------------------------------------------------------
