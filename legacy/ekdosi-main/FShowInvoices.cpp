//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include <System.RegularExpressions.hpp>
#include <xmldoc.hpp>
#include "Constants.h"

#include "FShowInvoices.h"
#include "CEditBox.h"
#include "RegistryAccess.h"
#include "FSelectCustomer.h"
#include "FSelectProduct.h"
#include "FEditInvoice.h"
#include "FPrint.h"
#include "FMailInvoices.h"
#include "CMyData.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxClasses"
#pragma link "cxCustomData"
#pragma link "cxData"
#pragma link "cxDataStorage"
#pragma link "cxDBData"
#pragma link "cxFilter"
#pragma link "cxGrid"
#pragma link "cxGridCustomTableView"
#pragma link "cxGridCustomView"
#pragma link "cxGridDBTableView"
#pragma link "cxGridLevel"
#pragma link "cxGridTableView"
#pragma link "cxNavigator"
#pragma link "cxStyles"
#pragma link "cxCalendar"
#pragma link "cxDropDownEdit"
#pragma link "cxMaskEdit"
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"

#pragma link "dxSkinBlack"
#pragma link "dxSkinBlue"
#pragma link "dxSkinBlueprint"
#pragma link "dxSkinCaramel"
#pragma link "dxSkinCoffee"
#pragma link "dxSkinDarkRoom"
#pragma link "dxSkinDarkSide"
#pragma link "dxSkinDevExpressDarkStyle"
#pragma link "dxSkinDevExpressStyle"
#pragma link "dxSkinFoggy"
#pragma link "dxSkinGlassOceans"
#pragma link "dxSkinHighContrast"
#pragma link "dxSkiniMaginary"
#pragma link "dxSkinLilian"
#pragma link "dxSkinLiquidSky"
#pragma link "dxSkinLondonLiquidSky"
#pragma link "dxSkinMcSkin"
#pragma link "dxSkinMetropolis"
#pragma link "dxSkinMetropolisDark"
#pragma link "dxSkinMoneyTwins"
#pragma link "dxSkinOffice2007Black"
#pragma link "dxSkinOffice2007Blue"
#pragma link "dxSkinOffice2007Green"
#pragma link "dxSkinOffice2007Pink"
#pragma link "dxSkinOffice2007Silver"
#pragma link "dxSkinOffice2010Black"
#pragma link "dxSkinOffice2010Blue"
#pragma link "dxSkinOffice2010Silver"
#pragma link "dxSkinOffice2013DarkGray"
#pragma link "dxSkinOffice2013LightGray"
#pragma link "dxSkinOffice2013White"
#pragma link "dxSkinOffice2016Colorful"
#pragma link "dxSkinOffice2016Dark"
#pragma link "dxSkinPumpkin"
#pragma link "dxSkinsCore"
#pragma link "dxSkinsDefaultPainters"
#pragma link "dxSkinSeven"
#pragma link "dxSkinSevenClassic"
#pragma link "dxSkinSharp"
#pragma link "dxSkinSharpPlus"
#pragma link "dxSkinSilver"
#pragma link "dxSkinSpringTime"
#pragma link "dxSkinStardust"
#pragma link "dxSkinSummer2008"
#pragma link "dxSkinTheAsphaltWorld"
#pragma link "dxSkinTheBezier"
#pragma link "dxSkinValentine"
#pragma link "dxSkinVisualStudio2013Blue"
#pragma link "dxSkinVisualStudio2013Dark"
#pragma link "dxSkinVisualStudio2013Light"
#pragma link "dxSkinVS2010"
#pragma link "dxSkinWhiteprint"
#pragma link "dxSkinXmas2008Blue"
#pragma resource "*.dfm"
TFrmShowInvoices *FrmShowInvoices;
//---------------------------------------------------------------------------
__fastcall TFrmShowInvoices::TFrmShowInvoices(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 btnAdd->Left = PanelSearch->Width - 2 - btnAdd->Width;

 //Point to the main dataset
 dataset = DatasetInvoice;

 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);
 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
// GridInvoices->Color = primary;
// GridInvoices->AlternateRowColor = secondary;

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 QrySelCustomer->Open();
 QryDistAim->Active = true;
 QryDeliveryMethod->Open();
 QryPaymentMeth->Open();
 QryInvTypes->Open();
 DatasetInvoice->Active = true;

 defaultSQL = new TStringList();

 defaultSQL->AddStrings(dataset->SelectSQL);

 EditBox *box = new EditBox(PanelSearch, SEARCH_HORZ_SPC,(editBoxes.size() * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC,ImageList1, 10);

 box->setClickEvent(btnMinusClick);
 box->setTextChangedEvent(editSearchChange);
 box->setField("INVCODE CUSTOMER.NAME", "Αρ. Παραστατικού:");
 box->setWidth(PanelSearch->Width);
 editBoxes.push_back(box);

 //Grid Events
// GridInvoices->OnDrawColumnCell = this->GridDrawColumnCell;
// GridInvoices->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
// GridInvoices->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
// GridInvoices->OnTitleBtnClick = this->GridTitleBtnClick;
// GridInvoices->OnUserSort = this->GridUserSort;

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;

 //Set the height of the search panel
 PanelTop->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;
 JvPageControl1->ActivePageIndex = 0;
}
//---------------------------------------------------------------------------

void TFrmShowInvoices::setInvoiceId(AnsiString _invCode)
{
	editBoxes[0]->setText(_invCode);
}

void TFrmShowInvoices::setCustomerId(int _id)
{
 QrySelCustomer->Active = false;
 QrySelCustomer->ParamByName("CUSTID")->AsInteger = _id;
 QrySelCustomer->Active = true;
 DatasetInvoice->FieldByName("CUST_ID")->AsInteger = QrySelCustomer->FieldByName("CUST_ID")->AsInteger;

 // showData(); SHOWINVOICE
}

void TFrmShowInvoices::setProductId(int _prId)
{
 QrySelProducts->Active = false;
 QrySelProducts->ParamByName("PRODUCTID")->AsInteger = _prId;
 QrySelProducts->Active = true;

 //showLineData();  SHOWINVOICE

}


void __fastcall TFrmShowInvoices::btnMinusClick(TObject *Sender)
{
 unsigned int pos = ((((TWinControl *)Sender)->Top -SEARCH_VERTL_SPC) / SEARCH_SPC_BTWN);
 bool refreshDataset = false;

 vector<EditBox *>::iterator w;
 w = editBoxes.begin();

 for(int i=0;i < pos;i++)
   w++;

 if((*w)->getFilter().Length() > 0)
  refreshDataset = true;

 EditBox *tmpBox = *w;
 tmpBox->Hide();
 editBoxes.erase(w);
 garbage.push_back(tmpBox);

 if(refreshDataset)
  editSearchChange(Sender);

 if(editBoxes.size() == 0)
  return;

 w = editBoxes.begin();
 unsigned int i = 0;
 while( w != editBoxes.end())
 {
  (*w)->setTop((i * SEARCH_SPC_BTWN)+SEARCH_VERTL_SPC);
  i++;
  w++;
 }

 PanelTop->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;
}

void __fastcall TFrmShowInvoices::editSearchChange(TObject *Sender)
{
 vector<EditBox *>::iterator w;

 TStringList *OrderByList = new TStringList();

 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-2]);
 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-1]);

 dataset->SelectSQL->Clear();
 for(int i =0;i<defaultSQL->Count-2;i++)
  dataset->SelectSQL->Add(defaultSQL->Strings[i]);

 w = editBoxes.begin();
 dataset->SelectSQL->Add("WHERE");
 while( w != editBoxes.end() )
 {
  if((*w)->getFilter().Length() > 0)
   dataset->SelectSQL->Add((*w)->getFilter());
  else
  {
   w++;
   continue;
  }
  w++;
  if(w != editBoxes.end())
   dataset->SelectSQL->Add("AND");
 }


 if(dataset->SelectSQL->Count > 0 &&
			(dataset->SelectSQL->Strings[dataset->SelectSQL->Count-1] == AnsiString("WHERE") ||
			dataset->SelectSQL->Strings[dataset->SelectSQL->Count-1] == "AND"))
 dataset->SelectSQL->Delete(dataset->SelectSQL->Count-1);

 dataset->SelectSQL->Add(OrderByList->Strings[0]);
 dataset->SelectSQL->Add(OrderByList->Strings[1]);
 //debuging
// Memo1->Lines = DatasetCustomer->SelectSQL;
 dataset->Active = false;
 dataset->Active = true;

 delete OrderByList;
}

void __fastcall TFrmShowInvoices::DatasetInvoiceAfterScroll(TDataSet *DataSet)
{
	showRecordsFetched();

 QrySelCustomer->Close();
	QrySelCustomer->ParamByName("CUST_ID")->AsInteger = DatasetInvoice->FieldByName("CUST_ID")->AsInteger;
	QrySelCustomer->Open();

	DatasetMyData->Close();
	DatasetMyData->ParamByName("INVOICE_ID")->AsInteger = DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger;
	DatasetMyData->Open();
	if(DatasetInvoice->FieldByName("MYDATA_SENT")->AsInteger == 1)
	{
		cmdSendMyData->Enabled = false;
	}
	else
	{
		cmdSendMyData->Enabled = true;
	}

	if(dataset->FieldByName("MYDATA_STATE")->AsString != "VALID")
		cmdSendMyData->Enabled = true;
	else
	 cmdSendMyData->Enabled = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::FormCloseQuery(TObject *Sender,
      bool &CanClose)
{
vector<EditBox *>::iterator w;
 w = garbage.begin();

 while( w != garbage.end() )
 {
  delete (*w);
  w++;
 }

 w = editBoxes.begin();
 while( w != editBoxes.end() )
 {
  delete (*w);
  w++;
 }

 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name,RollDetail->Collapsed);
 delete reg;
}

//---------------------------------------------------------------------------
void __fastcall TFrmShowInvoices::ToolEditClick(TObject *Sender)
{
// TFrmEditInvoice *frmEditInvoice = new TFrmEditInvoice(Owner,DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger);
 dataset->Edit();
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowInvoices::ToolDeleteClick(TObject *Sender)
{
 if(dataset->RecordCount==0)
  return;

	if(DatasetInvoice->FieldByName("MYDATA_SENT")->AsInteger != 0)
	{
	 if(showMessage("Έχει σταλεί στο myDATA! Να προχωρήσω στη διαγραφή;", MB_ICONEXCLAMATION | MB_YESNO) != 6)
			return;
 }

 try
 {
  dataset->Delete();
 }
 catch(Exception &e)
 {
  showMessage(e.Message.c_str(),ApplicationName,MB_ICONERROR);
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::JvDotNetButton1Click(TObject *Sender)
{
// TIBQuery *query = new TIBQuery(this);
 TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction,
			QryInvTypes->FieldByName("FRM_FILENAME")->AsString,
			"INVOICE_ID",
			dataset->FieldByName("INVOICE_ID")->AsInteger, true);

// QryInvTypes->FieldByName("INVTYPE")->AsString
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::FormShow(TObject *Sender)
{
 showRecordsFetched();
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowInvoices::JvDotNetButton4Click(TObject *Sender)
{
TFrmPrint *frmPrint = new TFrmPrint(Owner, transaction,
			QryInvTypes->FieldByName("FRM_FILENAME")->AsString,
			"INVOICE_ID",
			dataset->FieldByName("INVOICE_ID")->AsInteger, false, true);
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowInvoices::JvDotNetButton5Click(TObject *Sender)
{
 TFrmMailInvoices *FrmMail = new TFrmMailInvoices(Owner, DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger, false);
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowInvoices::DatasetInvoiceAfterEdit(TDataSet *DataSet)
{
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::ToolAcceptClick(TObject *Sender)
{
 dataset->Post();
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::DatasetInvoiceAfterPost(TDataSet *DataSet)
{
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::DatasetInvoiceAfterCancel(TDataSet *DataSet)
{
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}
//---------------------------------------------------------------------------


void __fastcall TFrmShowInvoices::cmdSendMyDataClick(TObject *Sender)
{
	if(dataset->FieldByName("MYDATA_STATE")->AsString == "VALID")
	{
		ShowMessage("Already sent to myDATA!");
  return;
	}

	if(DatasetInvoice->FieldByName("INVTYPE")->AsString == "INV")
	{
		showMessage("Το τιμολόγιο είναι ενδοκοινοτικό. Δεν μπορεί να σταλεί.", MB_ICONERROR);
	}
	else
	{
		MyData *myData = new MyData();
		myData->sendInvoice(DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger);
		delete myData;
		DatasetMyData->Close();
		DatasetMyData->ParamByName("INVOICE_ID")->AsInteger = DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger;
		DatasetMyData->Open();
 }
}

//---------------------------------------------------------------------------
void __fastcall TFrmShowInvoices::cmdCancelInvoiceClick(TObject *Sender)
{
	String mark = DatasetMyData->FieldByName("MARK")->AsString;
	MyData *myData = new MyData();
	std::pair<bool,AnsiString> res = myData->cancelInvoice(mark, DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger);
	if(!res.first)
	 showMessage(res.second, MB_ICONERROR);
	delete myData;
	DatasetMyData->Close();
	DatasetMyData->ParamByName("INVOICE_ID")->AsInteger = DatasetInvoice->FieldByName("INVOICE_ID")->AsInteger;
	DatasetMyData->Open();
}
//---------------------------------------------------------------------------

void __fastcall TFrmShowInvoices::DatasetMyDataAfterOpen(TDataSet *DataSet)
{
	if(DataSet->IsEmpty())
	{
		cmdCancelInvoice->Enabled = false;
	}
	else
	{
		cmdCancelInvoice->Enabled = true;
	}
}
//---------------------------------------------------------------------------
void __fastcall TFrmShowInvoices::DatasetMyDataAfterScroll(TDataSet *DataSet)
{
	if(DataSet->FieldByName("MYDATA_ACTION")->AsString == "INSERT")
	{
		cmdCancelInvoice->Enabled = true;
	}
	else
	{
		cmdCancelInvoice->Enabled = false;
 }
}
//---------------------------------------------------------------------------
