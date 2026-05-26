//---------------------------------------------------------------------------

#ifndef FAddProductH
#define FAddProductH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBDotNetControls.hpp"
#include "JvDotNetControls.hpp"
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <Mask.hpp>
#include "JvCheckedMaskEdit.hpp"
#include "JvDatePickerEdit.hpp"
#include "JvDBDatePickerEdit.hpp"
#include "JvExMask.hpp"
#include "JvMaskEdit.hpp"
#include "JvToolEdit.hpp"
#include "JvDBControls.hpp"
#include "JvEdit.hpp"
#include "JvExStdCtrls.hpp"
#include "JvDBLookup.hpp"
#include "JvExControls.hpp"
#include <ComCtrls.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
//---------------------------------------------------------------------------
class TFrmAddProduct : public NewSpecialForm
{
__published:	// IDE-managed Components
	TLabel *Label1;
	TLabel *Label2;
	TLabel *Label10;
	TLabel *Label11;
	TJvDotNetDBEdit *editDescription;
	TJvDotNetDBEdit *editOccupation;
	TJvDotNetDBEdit *editBuyPrice;
	TJvDotNetDBEdit *editSellPrice;
	TDataSource *DSProducts;
	TIBDataSet *DatasetProduct;
	TJvDotNetButton *JvDotNetButton1;
	TJvDotNetButton *JvDotNetButton2;
	TJvDotNetDBEdit *editPriceWVat;
	TLabel *Label3;
	TJvDotNetEdit *editMarkup;
	TIBQuery *QryVatCategories;
	TIntegerField *QryVatCategoriesVATCAT_ID;
	TIBStringField *QryVatCategoriesDESCRIPTION;
	TMemoField *QryVatCategoriesLONG_DESCRIPTION;
	TJvDBLookupCombo *JvDBLookupCombo1;
	TLabel *Label4;
	TDataSource *DSVatCategories;
	TIntegerField *DatasetProductPRODUCT_ID;
	TIBStringField *DatasetProductBARCODE;
	TIBStringField *DatasetProductDESCRIPTION_SHORT;
	TIntegerField *DatasetProductCAT_ID;
	TIntegerField *DatasetProductVATCAT_ID;
	TIBBCDField *DatasetProductBUY_PRICE;
	TIBBCDField *DatasetProductSELL_PRICE;
	TIBBCDField *DatasetProductPRICE_WVAT;
	TDateField *DatasetProductDATE_INSERTED;
	TMemoField *DatasetProductDESCRIPTION;
	TLabel *Label5;
	TJvDBLookupCombo *JvDBLookupCombo2;
	TIBQuery *QryPrCategories;
	TIntegerField *QryPrCategoriesCAT_ID;
	TIBStringField *QryPrCategoriesDESCRIPTION_SHORT;
	TMemoField *QryPrCategoriesDESCRIPTION;
	TDataSource *DSPrCategories;
	TLabel *Label6;
	TSmallintField *QryVatCategoriesDEFAULT_CAT;
	TLabel *Label12;
	TJvDotNetDBMemo *JvDotNetDBMemo1;
	TStatusBar *StatusBar1;
	TLabel *Label7;
	TJvDotNetDBEdit *JvDotNetDBEdit1;
	TLabel *Label8;
	TJvDotNetDBEdit *JvDotNetDBEdit2;
	TLabel *Label9;
	TIBQuery *QryMetricUnits;
	TDataSource *DSMetricUnits;
	TIntegerField *DatasetProductMETRIC_ID;
	TJvDBLookupCombo *JvDBLookupCombo3;
	TIntegerField *QryMetricUnitsMETRIC_ID;
	TIBStringField *QryMetricUnitsNAME;
	TMemoField *QryMetricUnitsNOTES;
	TIBBCDField *DatasetProductRESERVE;
	TIBBCDField *DatasetProductRESERVE_SECURE;
	TIBBCDField *QryVatCategoriesVALUE;
	void __fastcall FormShow(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall editMarkupExit(TObject *Sender);
	void __fastcall editMarkupKeyPress(TObject *Sender, char &Key);
	void __fastcall editBuyPriceExit(TObject *Sender);
	void __fastcall JvDBLookupCombo1Change(TObject *Sender);
	void __fastcall editPriceWVatExit(TObject *Sender);
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall editSellPriceExit(TObject *Sender);

private:	// User declarations
	bool checkDeps();
	void calcSaleWVat(); //Υπολόγισε Τιμή με ΦΠΑ
	void calcSalePrice();// Υπολόγισε τιμή πώλησης χωρίς ΦΠΑ
	void calcVatToSale(); //Υπολόγισε τιμή
public:		// User declarations
	__fastcall TFrmAddProduct(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmAddProduct *FrmAddProduct;
//---------------------------------------------------------------------------
#endif
