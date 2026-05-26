//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FAddProduct.h"
#include "FMain.h"
#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmAddProduct *FrmAddProduct;
//---------------------------------------------------------------------------
__fastcall TFrmAddProduct::TFrmAddProduct(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 dataset = DatasetProduct;

 dataset->Database = database;
 dataset->Transaction = transaction;
 dataset->Active = true;

 QryVatCategories->Database = database;
 QryVatCategories->Transaction = transaction;
 QryVatCategories->Active = true;

 QryPrCategories->Database = database;
 QryPrCategories->Transaction = transaction;
 QryPrCategories->Active = true;

 QryMetricUnits->Active = true;

 RegAccess *reg = new RegAccess(this);
 editMarkup->Text = AnsiString(reg->getAppParameterInt("MarkupPercent"));
 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmAddProduct::FormShow(TObject *Sender)
{
 dataset->Insert();

 QryVatCategories->Last();
 int recordCount = QryVatCategories->RecordCount;
 QryVatCategories->First();



 if(QryVatCategories->FieldByName("DEFAULT_CAT")->AsInteger == 1 )
 {
  DatasetProduct->FieldByName("VATCAT_ID")->AsInteger = QryVatCategories->FieldByName("VATCAT_ID")->AsInteger;
   return;
 }
 while(QryVatCategories->RecNo < recordCount)
 {
  QryVatCategories->Next();
  if(QryVatCategories->FieldByName("DEFAULT_CAT")->AsInteger == 1 )
  {
   DatasetProduct->FieldByName("VATCAT_ID")->AsInteger = QryVatCategories->FieldByName("VATCAT_ID")->AsInteger;
   break;
  }
 }
}
//---------------------------------------------------------------------------
void __fastcall TFrmAddProduct::JvDotNetButton1Click(TObject *Sender)
{
 if(!checkDeps())
  return;

 dataset->FieldByName("PRODUCT_ID")->AsInteger = 0;
 try
 {
  dataset->Post();
 }
 catch (EIBInterBaseError &e)
 {
//  if(e.IBErrorCode
 if(e.IBErrorCode == 335544665)
  showMessage("Ο κωδικός/ barcode είναι ήδη καταχωρημένος", ApplicationName, MB_ICONERROR);
 else
   showMessage(e.Message.c_str(), ApplicationName, MB_ICONERROR);
  return;
 }

 dataset->Insert();
 editDescription->SetFocus();
}
//---------------------------------------------------------------------------

bool TFrmAddProduct::checkDeps()
{
 if(dataset->FieldByName("DESCRIPTION_SHORT")->AsString.Length() == 0
		|| dataset->FieldByName("CAT_ID")->IsNull
		|| dataset->FieldByName("VATCAT_ID")->IsNull )
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά πεδία.",ApplicationName, MB_ICONERROR);
  return(false);
 }
 return(true);

}

void __fastcall TFrmAddProduct::editMarkupExit(TObject *Sender)
{
 if(editMarkup->Text.Length() == 0)
  editMarkup->Text = "0";
  
if(editMarkup->Text.ToInt() <0 || editMarkup->Text.ToInt() >100 )
 {
  showMessage("Παρακαλώ εισάγεται ποσοστό επι τοις εκατό.",ApplicationName, MB_ICONERROR);
  editMarkup->SetFocus();
  editMarkup->SelectAll();
 }
 calcSalePrice();
 
 CalcCursorPos();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddProduct::editMarkupKeyPress(TObject *Sender, char &Key)
{
 if( (Key < '0' || Key > '9') && Key != 8)
  Key = 0;	
}
//---------------------------------------------------------------------------



void TFrmAddProduct::calcSaleWVat()
{
 if(!dataset->FieldByName("SELL_PRICE")->IsNull && !QryVatCategories->FieldByName("VALUE")->IsNull)
 {
 double vat = 1+(QryVatCategories->FieldByName("VALUE")->AsFloat/100.00);
  dataset->FieldByName("PRICE_WVAT")->AsFloat = dataset->FieldByName("SELL_PRICE")->AsFloat * vat;
 }
}

void TFrmAddProduct::calcSalePrice()
{
  if(!dataset->FieldByName("BUY_PRICE")->IsNull && editMarkup->Text.Trim().Length() > 0)
 {
  double buyPrice=0;
  double markup=0;

  try
  {
   buyPrice = dataset->FieldByName("BUY_PRICE")->AsFloat;
   markup = 1.00 +((double)editMarkup->Text.ToInt() / 100.00);
  }
  catch (Exception &e)
  {
   ;
  }

  dataset->FieldByName("SELL_PRICE")->AsFloat = buyPrice * markup;
 }

 calcSaleWVat();
}
void __fastcall TFrmAddProduct::editBuyPriceExit(TObject *Sender)
{
 calcSalePrice();
}

//Υπολόγισε την τιμή μαζί με το ΦΠΑ
void TFrmAddProduct::calcVatToSale()
{
 if( !dataset->FieldByName("PRICE_WVAT")->IsNull && !QryVatCategories->FieldByName("VALUE")->IsNull)
 {
  double markup = 1.00 +(QryVatCategories->FieldByName("VALUE")->AsFloat / 100.00);
  dataset->FieldByName("SELL_PRICE")->AsFloat = dataset->FieldByName("PRICE_WVAT")->AsFloat / markup;
 }
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddProduct::JvDBLookupCombo1Change(TObject *Sender)
{
 calcSaleWVat();
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddProduct::editPriceWVatExit(TObject *Sender)
{
calcVatToSale();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmAddProduct::JvDotNetButton2Click(TObject *Sender)
{
 dataset->Cancel();
 Close();
}
//---------------------------------------------------------------------------



void __fastcall TFrmAddProduct::editSellPriceExit(TObject *Sender)
{
 calcSaleWVat();	
}
//---------------------------------------------------------------------------

