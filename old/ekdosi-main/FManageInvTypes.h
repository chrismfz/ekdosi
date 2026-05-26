//---------------------------------------------------------------------------

#ifndef FManageInvTypesH
#define FManageInvTypesH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBDotNetControls.hpp"
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExComCtrls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvRollOut.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <Mask.hpp>
#include <ToolWin.hpp>
#include "JvExMask.hpp"
#include "JvToolEdit.hpp"
#include "JvDBSpinEdit.hpp"
#include "JvSpin.hpp"
#include "JvExControls.hpp"
#include "JvLookOut.hpp"
#include "JvCheckBox.hpp"
#include "JvExStdCtrls.hpp"
#include "JvComCtrls.hpp"
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvDBLookup.hpp"

#include "CNewSpecialForm.h"
#include "cxCheckBox.hpp"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxDBEdit.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
#include <IBX.IBQuery.hpp>
//---------------------------------------------------------------------------
class TFrmManageInvTypes : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetInvTypes;
	TImageList *ImageList1;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolAdd;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvPanel *PanelMain;
	TJvDBUltimGrid *GridInvoiceTypes;
	TStatusBar *StatusBar1;
	TDataSource *DSInvTypes;
	TJvRollOut *RollDetail;
	TLabel *Label1;
	TJvDotNetDBEdit *editDescription;
	TLabel *Label2;
	TJvDotNetDBEdit *editInvCode;
	TJvDotNetDBEdit *JvDotNetDBEdit2;
	TLabel *Label3;
	TLabel *Label4;
	TIBStringField *DatasetInvTypesINVTYPE_ID;
	TIBStringField *DatasetInvTypesNAME;
	TIBStringField *DatasetInvTypesFRM_FILENAME;
	TIBStringField *DatasetInvTypesEAFDSS_SCRIPT;
	TJvFilenameEdit *editFilename;
	TLabel *Label5;
	TJvDBSpinEdit *JvDBSpinEdit1;
	TIntegerField *DatasetInvTypesINVCOUNT;
	TJvExpressButton *JvExpressButton1;
	TJvCheckBox *checkShowOnMenu;
	TSmallintField *DatasetInvTypesSHOW_ON_MENU;
	TJvPageControl *PageInvoices;
	TTabSheet *TabSheet1;
	TTabSheet *TabSheet2;
	TLabel *Label6;
	TJvDotNetButton *JvDotNetButton1;
	TJvDotNetEdit *editPrinter;
	TJvCheckBox *checkDotMatrix;
	TIBStringField *DatasetInvTypesPRINTER_NAME;
	TSmallintField *DatasetInvTypesPRINTER_NO;
	TIntegerField *DatasetInvTypesDISTAIM_ID;
	TIntegerField *DatasetInvTypesDELIVERYMETHOD_ID;
	TIntegerField *DatasetInvTypesPAYMETH_ID;
	TLabel *Label8;
	TJvDBLookupCombo *lookupDistrAim;
	TLabel *Label7;
	TJvDBLookupCombo *JvDBLookupCombo1;
	TLabel *Label9;
	TJvDBLookupCombo *JvDBLookupCombo2;
	TIBQuery *QryDistAim;
	TIntegerField *QryDistAimDISTAIM_ID;
	TIBStringField *QryDistAimDESCRIPTION;
	TDataSource *DSDistAim;
	TIBQuery *QryPaymentMethod;
	TDataSource *DSPaymentMethod;
	TIntegerField *QryPaymentMethodMETHOD_ID;
	TIBStringField *QryPaymentMethodDESCRIPTION;
	TIntegerField *QryPaymentMethodDUE_DAYS;
	TIBQuery *QryDeliveryMethod;
	TDataSource *DSDeliveryMethod;
	TIntegerField *QryDeliveryMethodMETHOD_ID;
	TIBStringField *QryDeliveryMethodDESCRIPTION;
	TIntegerField *DatasetInvTypesCUST_ID;
	TLabel *Label10;
	TJvDBLookupCombo *JvDBLookupCombo3;
	TIBQuery *QueryCustomer;
	TDataSource *DSCustomer;
	TIntegerField *QueryCustomerCUST_ID;
	TIBStringField *QueryCustomerAFM;
	TIBStringField *QueryCustomerNAME;
	TIBStringField *QueryCustomerADDRESS1;
	TIBStringField *QueryCustomerADDRESS2;
	TIBStringField *QueryCustomerCITY;
	TIBStringField *QueryCustomerPOSTCODE;
	TIBStringField *QueryCustomerPHONE1;
	TIBStringField *QueryCustomerPHONE2;
	TIBStringField *QueryCustomerFAX;
	TIBStringField *QueryCustomerOCCUPATION;
	TIBStringField *QueryCustomerTAXOFFICE;
	TMemoField *QueryCustomerDETAILS;
	TIBBCDField *QueryCustomerDISCOUNT;
	TIBStringField *QueryCustomerEMAIL;
	TIntegerField *QueryCustomerORDER;
	TSmallintField *DatasetInvTypesCREDITINVOICE;
	TSmallintField *DatasetInvTypesRETURNINVOICE;
	TcxDBCheckBox *cxDBCheckBox1;
	TcxDBCheckBox *cxDBCheckBox2;
	TTabSheet *MyData;
	TJvDotNetDBEdit *editMyDataType;
	TLabel *Label12;
	TIBStringField *DatasetInvTypesMYDATA_TYPE;
	TIBStringField *DatasetInvTypesMYDATA_INCOME_CLASS;
	TIBStringField *DatasetInvTypesMYDATA_INCOME_CLASS_CATEGORY;
	TLabel *Label11;
	TJvDotNetDBEdit *editMyDataIncomeClass;
	TJvDotNetDBEdit *editMyDataIncomeClassCategory;
	TLabel *Label13;
	void __fastcall GridInvoiceTypesUserSort(TJvDBUltimGrid *Sender,
          TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall editFilenameChange(TObject *Sender);
	void __fastcall DatasetInvTypesAfterCancel(TDataSet *DataSet);
	void __fastcall DatasetInvTypesAfterEdit(TDataSet *DataSet);
	void __fastcall DatasetInvTypesAfterPost(TDataSet *DataSet);
	void __fastcall DatasetInvTypesAfterInsert(TDataSet *DataSet);
	void __fastcall DatasetInvTypesAfterOpen(TDataSet *DataSet);
	void __fastcall DatasetInvTypesAfterScroll(TDataSet *DataSet);
	void __fastcall JvExpressButton1Click(TObject *Sender);
	void __fastcall checkShowOnMenuClick(TObject *Sender);
	void __fastcall DatasetInvTypesBeforeEdit(TDataSet *DataSet);
	void __fastcall DatasetInvTypesBeforeInsert(TDataSet *DataSet);
	void __fastcall FormShow(TObject *Sender);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmManageInvTypes(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageInvTypes *FrmManageInvTypes;
//---------------------------------------------------------------------------
#endif
