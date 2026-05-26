//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FAddInvoice2.h"
#include "FMain.h"
#include "FSelectCustomer.h"
#include "FSelectProduct.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxCalendar"
#pragma link "cxContainer"
#pragma link "cxControls"
#pragma link "cxDBEdit"
#pragma link "cxDBLookupComboBox"
#pragma link "cxDBLookupEdit"
#pragma link "cxDropDownEdit"
#pragma link "cxEdit"
#pragma link "cxGraphics"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma link "cxLookupEdit"
#pragma link "cxMaskEdit"
#pragma link "cxTextEdit"
#pragma link "JvExExtCtrls"
#pragma link "JvExtComponent"
#pragma link "JvPanel"
#pragma link "cxMemo"
#pragma link "JvDotNetControls"
#pragma link "JvEdit"
#pragma link "JvExMask"
#pragma link "JvExStdCtrls"
#pragma link "JvMenus"
#pragma link "JvSpin"
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
TFrmAddInvoice2 *FrmAddInvoice2;
//---------------------------------------------------------------------------
__fastcall TFrmAddInvoice2::TFrmAddInvoice2(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  runningDate = ((TFrmMain *)Owner)->getRunningDate();

 QryInvTypes->Active = true;
 QryDistAim->Active = true;
 QryDeliveryMethod->Active = true;
 QryPaymentMeth->Active = true;
 QryVatCategory->Active = true;
 QrySelProducts->Active = true;

 dataset = DatasetInvoice;
 dataset->Active = true;
 dataset->Insert();
 DatasetInvLines->Active = true;

}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice2::comboInvTypePropertiesChange(TObject *Sender)
{
 if(DatasetInvoice->State == dsInsert || DatasetInvoice->State == dsEdit)
 {
  DatasetInvoice->FieldByName("DISTRAIM_ID")->AsInteger =  QryInvTypes->FieldByName("DISTAIM_ID")->AsInteger;
  DatasetInvoice->FieldByName("DELMETHOD_ID")->AsInteger =  QryInvTypes->FieldByName("DELIVERYMETHOD_ID")->AsInteger;

  if(QrySelCustomer->FieldByName("PAYMETH_ID")->IsNull == true )
	DatasetInvoice->FieldByName("PAYMETH_ID")->AsInteger =  QryInvTypes->FieldByName("PAYMETH_ID")->AsInteger;
  else
	DatasetInvoice->FieldByName("PAYMETH_ID")->AsInteger =  QrySelCustomer->FieldByName("PAYMETH_ID")->AsInteger;
 }
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddInvoice2::editNameKeyDown(TObject *Sender, WORD &Key, TShiftState Shift)
{
 if( Key == 120)//F9
 {
  this->Enabled = false;
  TFrmSelectCustomer *frmSelectCustomer = new TFrmSelectCustomer(Owner, this, setCustomerId, "", editVatNo->Text);
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice2::editVatNoKeyPress(TObject *Sender, System::WideChar &Key)
{
 if( (Key < '0' || Key > '9' )&& Key != 8)
  Key = 0;
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice2::ViewInvoiceLinesKeyDown(TObject *Sender, WORD &Key, TShiftState Shift)
{
 if(Key == 45) //insert
 {
  MenuAddLine->Click();
  Key = 0 ;
 }
 else if(Key == 46)//delete record -- delete key
 {
  MenuDeleteLine->Click();
 }
 else if(Key == 69 && DatasetInvLines->RecordCount > 0 && !( DatasetInvLines->State == dsEdit || DatasetInvLines->State == dsInsert ) ) // E key
 {
  MenuEditLine->Click();
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice2::MenuAddLineClick(TObject *Sender)
{
 ViewInvoiceLines->OptionsSelection->CellSelect = false;
// GridInvoiceLines->Options = GridInvoiceLines->Options >> dgRowSelect;
// GridInvoiceLines->Options = GridInvoiceLines->Options << dgEditing;
 DatasetInvLines->Append();
 cmdSearch->Show();

}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice2::cmdSearchClick(TObject *Sender)
{
 unsigned short Key = 120;
 TShiftState a;
 ViewInvoiceLinesKeyDown(Sender,Key,a);
}
//---------------------------------------------------------------------------

void TFrmAddInvoice2::setCustomerId(int _id)
{
 QrySelCustomer->Active = false;
 QrySelCustomer->ParamByName("CUSTID")->AsInteger = _id;
 QrySelCustomer->Active = true;
 DatasetInvoice->FieldByName("CUST_ID")->AsInteger = QrySelCustomer->FieldByName("CUST_ID")->AsInteger;
 showData();
}

void TFrmAddInvoice2::setProductId(int _prId)
{
 QrySelProducts->Active = false;
 QrySelProducts->ParamByName("BARCODE")->Clear();
 QrySelProducts->ParamByName("PRODUCTID")->AsInteger = _prId;
 QrySelProducts->Active = true;

 showLineData();
 GridInvoiceLines->SetFocus();
}

void TFrmAddInvoice2::showData()
{
 editName->Text = QrySelCustomer->FieldByName("NAME")->AsString;
 editVatNo->Text = QrySelCustomer->FieldByName("AFM")->AsString;
 editOccupation->Text = QrySelCustomer->FieldByName("OCCUPATION")->AsString;
// editAddress1->Text = QrySelCustomer->FieldByName("ADDRESS1")->AsString;
// editAddress2->Text = QrySelCustomer->FieldByName("ADDRESS2")->AsString;
 DatasetInvoice->FieldByName("ADDRESS1")->AsString = QrySelCustomer->FieldByName("ADDRESS1")->AsString;
 DatasetInvoice->FieldByName("ADDRESS2")->AsString = QrySelCustomer->FieldByName("ADDRESS2")->AsString;
 DatasetInvoice->FieldByName("CITY")->AsString = QrySelCustomer->FieldByName("CITY")->AsString;
 DatasetInvoice->FieldByName("POSTCODE")->AsString = QrySelCustomer->FieldByName("POSTCODE")->AsString;
}

void TFrmAddInvoice2::showLineData()
{
 DatasetInvLines->FieldByName("PRODUCT_ID")->AsString = QrySelProducts->FieldByName("PRODUCT_ID")->AsString;
 DatasetInvLines->FieldByName("BARCODE")->AsString = QrySelProducts->FieldByName("BARCODE")->AsString;
 DatasetInvLines->FieldByName("DESCRIPTION_SHORT")->AsString = QrySelProducts->FieldByName("DESCRIPTION_SHORT")->AsString;
 DatasetInvLines->FieldByName("VATPERCENT")->AsFloat = QrySelProducts->FieldByName("VAT_VALUE")->AsFloat;
 DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency = QrySelProducts->FieldByName("SELL_PRICE")->AsCurrency;

 calcPrices();
}

void TFrmAddInvoice2::calcPrices()
{
 DatasetInvLines->FieldByName("PRICE")->AsCurrency = (DatasetInvLines->FieldByName("QTY")->AsFloat * DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency);

 double discountValue = DatasetInvLines->FieldByName("PRICE")->AsCurrency * (DatasetInvLines->FieldByName("DISCOUNT")->AsFloat/100);
 DatasetInvLines->FieldByName("PRICE")->AsCurrency = DatasetInvLines->FieldByName("PRICE")->AsCurrency - discountValue;
 DatasetInvLines->FieldByName("PRICEWVAT")->AsCurrency =  DatasetInvLines->FieldByName("PRICE")->AsCurrency *((DatasetInvLines->FieldByName("VATPERCENT")->AsFloat/100)+1);
// if(GridInvoiceLines->SelectedField->FieldName != "PRICE_PER_ITEM_WVAT")
 if(ViewInvoiceLines->Controller->FocusedColumn->Name == "ViewInvoiceLinesPRICE_PER_ITEM_WVAT")
  DatasetInvLines->FieldByName("PRICE_PER_ITEM_WVAT")->AsCurrency = DatasetInvLines->FieldByName("PRICE_PER_ITEM")->AsCurrency * ((DatasetInvLines->FieldByName("VATPERCENT")->AsFloat/100)+1);
}

void __fastcall TFrmAddInvoice2::MenuDeleteLineClick(TObject *Sender)
{
 if(DatasetInvLines->RecordCount > 0)
   DatasetInvLines->Delete();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddInvoice2::MenuEditLineClick(TObject *Sender)
{
 ViewInvoiceLines->OptionsSelection->CellSelect = false;

 /* Depreciated
 TJvDBUltimGrid *grid = GridInvoiceLines;
 grid->Options = grid->Options >> dgRowSelect;
 grid->Options = grid->Options << dgEditing;*/
 DatasetInvLines->Edit();
}
//---------------------------------------------------------------------------


void __fastcall TFrmAddInvoice2::DatasetInvoiceINVDATEChange(TField *Sender)
{
 if(DatasetInvoice->FieldByName("DELIVERYDATE")->IsNull && (DatasetInvoice->State == dsInsert || DatasetInvoice->State == dsEdit))
  DatasetInvoice->FieldByName("DELIVERYDATE")->AsDateTime = DatasetInvoice->FieldByName("INVDATE")->AsDateTime;
}
//---------------------------------------------------------------------------

